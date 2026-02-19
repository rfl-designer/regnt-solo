<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $document->title }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #1a1a1a;
            padding: 40px;
        }

        /* Header */
        .header {
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header-type {
            display: inline-block;
            background-color: {{ $typeColor }};
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .header-title {
            font-size: 24px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 8px;
        }

        .header-meta {
            font-size: 11px;
            color: #6b7280;
        }

        .header-meta span {
            margin-right: 20px;
        }

        /* Content */
        .content {
            line-height: 1.8;
        }

        .content h1 {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
            margin-top: 24px;
            margin-bottom: 12px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
        }

        .content h2 {
            font-size: 16px;
            font-weight: bold;
            color: #1f2937;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .content h3 {
            font-size: 14px;
            font-weight: bold;
            color: #374151;
            margin-top: 16px;
            margin-bottom: 8px;
        }

        .content p {
            margin-bottom: 12px;
            text-align: justify;
        }

        .content ul, .content ol {
            margin-bottom: 12px;
            padding-left: 24px;
        }

        .content li {
            margin-bottom: 4px;
        }

        .content code {
            background-color: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 11px;
        }

        .content pre {
            background-color: #1f2937;
            color: #e5e7eb;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            overflow-x: auto;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 10px;
            line-height: 1.5;
        }

        .content pre code {
            background-color: transparent;
            padding: 0;
            color: inherit;
        }

        .content blockquote {
            border-left: 4px solid #3b82f6;
            padding-left: 16px;
            margin: 16px 0;
            color: #4b5563;
            font-style: italic;
        }

        .content table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }

        .content th, .content td {
            border: 1px solid #d1d5db;
            padding: 8px 12px;
            text-align: left;
        }

        .content th {
            background-color: #f3f4f6;
            font-weight: bold;
        }

        .content hr {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 24px 0;
        }

        /* Checkbox list */
        .content input[type="checkbox"] {
            margin-right: 8px;
        }

        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #9ca3af;
            text-align: center;
        }

        /* Page break */
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-type">{{ $document->type->label() }}</div>
        <h1 class="header-title">{{ $document->title }}</h1>
        <div class="header-meta">
            @if ($document->project)
                <span>Projeto: {{ $document->project->name }}</span>
            @endif
            <span>Criado em: {{ $document->created_at->format('d/m/Y H:i') }}</span>
            <span>Atualizado em: {{ $document->updated_at->format('d/m/Y H:i') }}</span>
        </div>
    </div>

    <div class="content">
        {!! $htmlContent !!}
    </div>

    <div class="footer">
        Exportado do SoloBoard em {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
