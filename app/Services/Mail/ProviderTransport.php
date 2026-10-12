<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/** Laravel's "platform" mailer: Mail::to(...)->send(...) goes through EmailSender's providers. */
class ProviderTransport extends AbstractTransport
{
    public function __construct(private readonly EmailSender $sender)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $from = $email->getFrom()[0] ?? null;
        $reply = $email->getReplyTo()[0] ?? null;

        foreach ($email->getTo() as $to) {
            $outgoing = $html !== null
                ? new OutgoingEmail($to->getAddress(), (string) $email->getSubject(), (string) $html, $text !== null ? (string) $text : null, $to->getName() ?: null)
                : OutgoingEmail::fromText($to->getAddress(), (string) $email->getSubject(), (string) $text, ['toName' => $to->getName() ?: null]);
            $outgoing->fromName = $from?->getName() ?: null;
            $outgoing->replyTo = $reply?->getAddress();
            $result = $this->sender->send($outgoing);
            if (! $result->ok()) {
                throw new TransportException('Email not sent: '.$result->error);
            }
        }
    }

    public function __toString(): string
    {
        return 'platform';
    }
}
