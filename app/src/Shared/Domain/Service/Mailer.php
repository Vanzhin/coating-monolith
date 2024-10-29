<?php
declare(strict_types=1);


namespace App\Shared\Domain\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class Mailer
{
    private string $appName;
    private string $defaultFromEmail;
    private string $defaultFromName;

    public function __construct(
        private readonly MailerInterface $mailer,
        string                           $appName,
        string                           $defaultFromEmail,
        string                           $defaultFromName,
    )
    {
        $this->appName = $appName;
        $this->defaultFromEmail = $defaultFromEmail;
        $this->defaultFromName = $defaultFromName;
    }

    public function sendLoginLinkEmail(Address $to, string $uri): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->defaultFromEmail, $this->defaultFromName))
            ->to($to)
            ->subject(sprintf('Ссылка для входа в %s', $this->appName))
            ->htmlTemplate('emails/login_link.html.twig')
            ->context([
                'appName' => $this->appName,
                'uri' => $uri
            ]);
        $this->mailer->send($email);
    }
}