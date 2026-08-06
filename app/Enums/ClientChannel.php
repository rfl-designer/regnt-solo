<?php

namespace App\Enums;

enum ClientChannel: string
{
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Slack = 'slack';
    case Other = 'other';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::Email => 'E-mail',
            self::Slack => 'Slack',
            self::Other => 'Outro',
        };
    }

    /**
     * Get the color associated with this channel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Whatsapp => 'emerald',
            self::Email => 'blue',
            self::Slack => 'violet',
            self::Other => 'zinc',
        };
    }

    /**
     * Get the icon associated with this channel.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Whatsapp => 'chat-bubble-left-right',
            self::Email => 'envelope',
            self::Slack => 'hashtag',
            self::Other => 'ellipsis-horizontal-circle',
        };
    }
}
