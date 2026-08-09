<?php

namespace App\Enums;

/**
 * O que fez um cliente entrar na categoria "evento" da fila de updates
 * (issue #150).
 *
 * Cem por cento derivado do estado do quadro — não há tabela, coluna nem
 * flag de "gatilho disparado". Um gatilho é a resposta a uma pergunta feita
 * na hora sobre a janela desde o último envio, e é isso que faz "enviar"
 * apagá-lo sem que nada precise ser marcado como lido.
 *
 * A ordem de declaração é a ordem em que os chips aparecem na linha: uma
 * Emergência é mais alta que uma entrega, e quem bate o olho na fila deve
 * ler primeiro o que grita mais.
 */
enum UpdateTrigger: string
{
    case Emergency = 'emergency';
    case DeliveryAwaitingValidation = 'delivery_awaiting_validation';

    /**
     * O texto do chip na linha da fila.
     */
    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Emergência',
            self::DeliveryAwaitingValidation => 'Entrega aguardando validação',
        };
    }

    /**
     * A frase que explica o gatilho por extenso — o que o MCP publica e o
     * que o `title` do chip diz.
     */
    public function reason(): string
    {
        return match ($this) {
            self::Emergency => 'Emergência de um item deste cliente aberta ou concluída desde o último envio.',
            self::DeliveryAwaitingValidation => 'Uma spec deste cliente foi entregue para validação desde o último envio.',
        };
    }

    /**
     * As cores são emprestadas de quem já as usa no quadro: a Emergência tem
     * a cor da classe de serviço, a entrega tem a cor da coluna Aguardando
     * validação. Um chip novo com uma cor nova faria a fila parecer falar de
     * outra coisa.
     */
    public function color(): string
    {
        return match ($this) {
            self::Emergency => ServiceClass::Emergency->color(),
            self::DeliveryAwaitingValidation => ActivityStatus::AwaitingValidation->color(),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Emergency => ServiceClass::Emergency->icon(),
            self::DeliveryAwaitingValidation => ActivityStatus::AwaitingValidation->icon(),
        };
    }

    /**
     * Põe uma lista de gatilhos na ordem de declaração, sem repetições —
     * dois itens do mesmo cliente entregues na mesma janela são um chip.
     *
     * @param  iterable<UpdateTrigger>  $triggers
     * @return list<UpdateTrigger>
     */
    public static function order(iterable $triggers): array
    {
        $found = [];

        foreach ($triggers as $trigger) {
            $found[$trigger->value] = $trigger;
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => isset($found[$case->value]),
        ));
    }
}
