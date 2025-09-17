<?php
declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment as Twig;

final class PdfGenerator
{
    public function __construct(private readonly Twig $twig) {}

    public function render(string $template, array $context = []): string
    {
        $html = $this->twig->render($template, $context);
        $html = $this->toUtf8($html); // nettoyage UTF-8 sans iconv()

        $opt = new Options();
        $opt->set('isRemoteEnabled', true);
        $opt->set('isHtml5ParserEnabled', true);
        $opt->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($opt);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');

        // ⚠️ Pare-chocs : ignore UNIQUEMENT les Notices "iconv()" pendant render()/output()
        set_error_handler(function ($severity, $message) {
            return $severity === E_NOTICE && str_contains($message ?? '', 'iconv()');
        });
        try {
            $dompdf->render();
            $out = $dompdf->output();
        } finally {
            restore_error_handler();
        }

        return $out;
    }

    private function toUtf8(string $s): string
    {
        $enc = \mb_detect_encoding($s, ['UTF-8','Windows-1252','ISO-8859-1','ISO-8859-15'], true) ?: 'UTF-8';
        if ($enc !== 'UTF-8') {
            $s = \mb_convert_encoding($s, 'UTF-8', $enc);
        }
        if (!\mb_check_encoding($s, 'UTF-8')) {
            $s = \mb_convert_encoding($s, 'UTF-8', 'UTF-8'); // nettoie les séquences invalides
        }
        return $s;
    }
}
