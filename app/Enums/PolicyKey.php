<?php

namespace App\Enums;

use App\Services\BoardPolicyService;

/**
 * The three human policies the board keeps in writing (issue #154).
 *
 * Deliberately a fixed enum rather than user-created sections: the value
 * of a versioned policy is being able to read "a Definição de Feito mudou
 * em 12/03" against a change in the flow metrics, and that reading only
 * works if the set of questions is stable. A free-form section list would
 * turn the history into a pile of notes.
 *
 * Everything *mechanical* — WIP limit, the single Emergência, the degraus
 * of the pull queue, the risk window — is deliberately absent: those are
 * rendered from the real state in
 * {@see BoardPolicyService::mechanics()} and can never be
 * edited into disagreeing with the behaviour.
 */
enum PolicyKey: string
{
    case DefinitionOfDone = 'definition_of_done';
    case DefinitionOfReady = 'definition_of_ready';
    case WorkingAgreements = 'working_agreements';

    /**
     * The PT-BR heading of the section.
     */
    public function label(): string
    {
        return match ($this) {
            self::DefinitionOfDone => 'Definição de Feito',
            self::DefinitionOfReady => 'Definição de Pronto',
            self::WorkingAgreements => 'Acordos de trabalho',
        };
    }

    /**
     * One line saying why the section exists, shown under the heading.
     */
    public function hint(): string
    {
        return match ($this) {
            self::DefinitionOfDone => 'O que precisa ser verdade para um item entrar em Feito.',
            self::DefinitionOfReady => 'O que precisa ser verdade para um item entrar em Pronto — a entrada em Pronto é o relógio do FIFO e da SLE.',
            self::WorkingAgreements => 'Como eu trabalho no quadro: o que combino comigo mesmo.',
        };
    }

    /**
     * The icon of the section's heading.
     */
    public function icon(): string
    {
        return match ($this) {
            self::DefinitionOfDone => 'check-circle',
            self::DefinitionOfReady => 'arrow-right-circle',
            self::WorkingAgreements => 'hand-raised',
        };
    }

    /**
     * The v1 the seeder writes: the method's default, in the shortest
     * honest wording. It is a starting point to be edited, not a
     * constitution — which is exactly why it is seeded as a version like
     * any other, with its own note.
     */
    public function seedBody(): string
    {
        return match ($this) {
            self::DefinitionOfDone => <<<'MD'
                Um item está **Feito** quando:

                - o que foi combinado está entregue e rodando onde o cliente vê;
                - os testes que cobrem a mudança passam;
                - o que mudou está registrado onde a próxima pessoa (ou o próximo eu) vai procurar;
                - não sobrou nenhuma pendência minha — se sobrou, o item ainda não é Feito, é Esperando.
                MD,
            self::DefinitionOfReady => <<<'MD'
                Um item pode entrar em **Pronto** quando:

                - dá para começar hoje sem esperar resposta de ninguém;
                - o resultado esperado está escrito de um jeito que dá para saber quando terminou;
                - tem classe de serviço definida;
                - se é fatia de um Épico, a Spec desse Épico já foi aprovada.

                Enquanto qualquer uma dessas faltar, o lugar é Backlog — a entrada em Pronto é o começo do relógio.
                MD,
            self::WorkingAgreements => <<<'MD'
                - Puxar, nunca empurrar: só começo item novo quando abre vaga em Fazendo.
                - A ordem de puxar é a fila do quadro, não a minha vontade do dia.
                - Bloqueou? O item vai para a coluna de espera com o "esperando quem" preenchido, no mesmo instante.
                - Emergência é exceção medida: entra com motivo escrito, e só uma por vez.
                - Intangível também é trabalho — se a fome de Intangível acende, ela vem antes do próximo Padrão.
                - Update do cliente sai na cadência combinada, mesmo que a semana tenha sido curta.
                MD,
        };
    }
}
