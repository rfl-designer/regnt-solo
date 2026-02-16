<x-mail::message>
# Olá, {{ $stakeholder->name }}!

Você foi adicionado como stakeholder no projeto **{{ $project->name }}**.

Com este link, você pode acompanhar o progresso do projeto em tempo real, incluindo:

- Kanban com todas as tasks
- Documentos do projeto
- Métricas e progresso

<x-mail::button :url="$publicUrl">
Acessar Projeto
</x-mail::button>

Este link é pessoal e exclusivo para você. Não compartilhe com outras pessoas.

A visualização é somente leitura — você pode acompanhar, mas não editar o projeto.

Atenciosamente,<br>
{{ config('app.name') }}
</x-mail::message>
