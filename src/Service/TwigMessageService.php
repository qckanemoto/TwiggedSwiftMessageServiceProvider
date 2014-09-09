<?php

namespace Quartet\Silex\Service;

use Quartet\Silex\Exception\RuntimeException;
use Quartet\Silex\Twig\Extension\TwigMessageExtension;
use Silex\Application;
use Symfony\Component\Form\Form;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class TwigMessageService
{
    private $twig;

    /**
     * @param \Twig_Environment $twig
     * @param \Swift_Mailer $mailer
     * @param array $options
     */
    public function __construct(\Twig_Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * @param string $templatePath
     * @param Form $form
     * @return \Swift_Mime_MimePart
     */
    public function buildMessage($templatePath, array $vars = array(), Form $form = null)
    {
        /** @var $template \Twig_Template */
        $template = $this->twig->loadTemplate($templatePath);
        $contentType = preg_match('/\.html(\.twig)?$/', $templatePath) ? 'text/html' : 'text/plain';

        $message = \Swift_Message::newInstance();

        // build hashtable from Form.
        $form = !is_null($form) ? $this->getFormData($form) : array();

        $params = compact('vars', 'form');

        // build message from twig template.
        if ($from = $template->renderBlock('from', $params)) {
            if ($fromName = $template->renderBlock('from_name', $params)) {
                $message->setFrom($from, $fromName);
            } else {
                $message->setFrom($from);
            }
        }
        if ($to = $template->renderBlock('to', $params)) {
            $message->setTo($to);
        }
        if ($cc = $template->renderBlock('cc', $params)) {
            $message->setCc($cc);
        }
        if ($bcc = $template->renderBlock('bcc', $params)) {
            $message->setBcc($bcc);
        }
        if ($replyTo = $template->renderBlock('reply_to', $params)) {
            $message->setReplyTo($replyTo);
        }
        if ($subject = $template->renderBlock('subject', $params)) {
            $message->setSubject($subject);
        }
        if ($body = $template->renderBlock('body', $params)) {
            $message->setBody($body, $contentType);
        }

        return $message;
    }

    /**
     * @param \Swift_Message $message
     * @param $style
     * @return \Swift_Message
     * @throws \Quartet\Silex\Exception\RuntimeException
     */
    public function setInlineStyle(\Swift_Message $message, $style)
    {
        if ($message->getContentType() !== 'text/html') {
            throw new RuntimeException('Plain text message cannot be styled.');
        }

        $html = $message->getBody();
        $setter = new CssToInlineStyles($html, $style);
        $styledHtml = $setter->convert();
        $message->setBody($styledHtml);

        return $message;
    }

    /**
     * @param \Swift_Message $message
     * @return \Swift_Message
     */
    public function finishEmbedImage(\Swift_Message $message)
    {
        $body = $message->getBody();

        $extension = new TwigMessageExtension();
        $identifier = $extension->getName();

        preg_match_all("/%{$identifier}%([^%]*)%/", $body, $matches);

        for ($i = 0; isset($matches[0][$i]); $i++) {
            $pattern = $matches[0][$i];

            $filePath = $matches[1][$i];
            $replacement = $message->embed(\Swift_Image::fromPath($filePath));

            $body = str_replace($pattern, $replacement, $body);
        }

        $message->setBody($body);

        return $message;
    }

    /**
     * @param \Swift_Message $message
     * @return string
     */
    public function renderBody(\Swift_Message $message)
    {
        $body = $message->getBody();

        $extension = new TwigMessageExtension();
        $identifier = $extension->getName();

        preg_match_all("/%{$identifier}%([^%]*\.([^%.]+))%/", $body, $matches);

        for ($i = 0; isset($matches[0][$i]); $i++) {
            $pattern = $matches[0][$i];

            $filePath = $matches[1][$i];
            $ext = $matches[2][$i];
            $replacement = "data:image/{$ext};base64," . base64_encode(file_get_contents($filePath));

            $body = str_replace($pattern, $replacement, $body);
        }

        return $body;
    }

    private function getFormData(Form $form)
    {
        $data = array();

        foreach ($form->getIterator() as $child) {
            /** @var $child \Symfony\Component\Form\Form */
            $value = $child->getData();

            // process hashtable recursively.
            if (is_array($value) && array_values($value) !== $value) {
                $data[$child->getName()] = $this->getData($child);
            } else {
                $label = $child->getConfig()->getOption('label');
                $data[$child->getName()] = array(
                    'label' => $label ?: $this->humanize($child->getName()),
                    'value' => $value,
                );
            }
        }

        return $data;
    }

    private function humanize($text)
    {
        return ucfirst(trim(strtolower(preg_replace(array('/([A-Z])/', '/[_\s]+/'), array('_$1', ' '), $text))));
    }
}
