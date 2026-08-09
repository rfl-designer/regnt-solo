<?php

namespace App\Enums;

/**
 * Onde a spec está na colina (issue #149).
 *
 * Duas posições, marcadas à mão no modal do Épico, e nada entre elas. A
 * subida é descobrir o que precisa ser feito; a descida é fazer o que já se
 * sabe. É o que um cliente consegue usar numa frase — e o que o dev
 * consegue responder sem se enganar.
 */
enum HillPosition: string
{
    case Uphill = 'uphill';
    case Downhill = 'downhill';

    /**
     * O rótulo do toggle, em PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Uphill => 'Em descoberta',
            self::Downhill => 'Em execução',
        };
    }

    /**
     * A frase como ela entra no bloco "Em andamento" do update — minúscula,
     * porque é continuação da linha do item, não um título.
     */
    public function phrase(): string
    {
        return match ($this) {
            self::Uphill => 'em descoberta',
            self::Downhill => 'em execução',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Uphill => 'amber',
            self::Downhill => 'emerald',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Uphill => 'arrow-trending-up',
            self::Downhill => 'arrow-trending-down',
        };
    }
}
