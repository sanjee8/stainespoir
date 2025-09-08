<?php

namespace App\Twig;

use App\Service\SettingProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SettingExtension extends AbstractExtension
{
    public function __construct(private readonly SettingProvider $settings) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('setting', [$this, 'setting']),
            new TwigFunction('site', [$this, 'site']),
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->get($key, $default);
    }

    /** Renvoie un petit objet/array direct pour le footer/header */
    public function site(): array
    {
        return [
            'phone'     => $this->settings->get('site.phone', ''),
            'email'     => $this->settings->get('site.email', ''),
            'instagram' => $this->settings->get('social.instagram_url', ''),
        ];
    }
}
