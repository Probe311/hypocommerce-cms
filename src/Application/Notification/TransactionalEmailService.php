<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Infrastructure\Logging\StructuredLogger;

final class TransactionalEmailService
{
    private StructuredLogger $logger;

    public function __construct()
    {
        $this->logger = new StructuredLogger();
    }

    /**
     * @param array<string,string> $variables
     */
    public function send(string $template, string $toEmail, array $variables = []): bool
    {
        $subject = $this->subjectForTemplate($template);
        $body = $this->renderBody($template, $variables);
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= 'From: ' . (string) ($_ENV['MAIL_FROM'] ?? 'noreply@example.com');

        $sent = @mail($toEmail, $subject, $body, $headers);
        $this->logger->info('transactional_email', [
            'template' => $template,
            'to' => $toEmail,
            'sent' => $sent,
        ]);
        return $sent;
    }

    private function subjectForTemplate(string $template): string
    {
        return match ($template) {
            'account_created' => 'Bienvenue sur votre boutique',
            'password_reset_requested' => 'Reinitialisation de votre mot de passe',
            'password_reset_done' => 'Votre mot de passe a ete modifie',
            'order_created' => 'Votre commande a bien ete creee',
            'order_paid' => 'Paiement confirme',
            'order_refunded' => 'Remboursement confirme',
            'order_shipped' => 'Votre commande est expediee',
            'abandoned_cart_reminder' => 'Vous avez des articles en attente',
            default => 'Notification boutique',
        };
    }

    /**
     * @param array<string,string> $variables
     */
    private function renderBody(string $template, array $variables): string
    {
        $lines = ['Template: ' . $template, ''];
        foreach ($variables as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $lines[] = '';
        $lines[] = 'Merci.';
        return implode(PHP_EOL, $lines);
    }
}
