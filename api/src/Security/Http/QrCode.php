<?php

declare(strict_types=1);

namespace App\Security\Http;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR code en SVG (BaconQrCode, sans extension GD), remis en URI de données : le front l'affiche dans un <img>, où un SVG ne
 * peut exécuter aucun script, et n'a ni bibliothèque de QR code à embarquer ni HTML à injecter (éco-conception, XSS).
 * Le contenu est l'URI otpauth:// qui porte le secret : la réponse qui le contient n'est jamais mise en cache.
 */
final class QrCode
{
    public static function uriDonnees(string $contenu): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd())))->writeString($contenu);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
