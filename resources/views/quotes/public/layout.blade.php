<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quote')</title>
    <style>
        :root {
            --ink: #1c1917;
            --muted: #57534e;
            --line: #e7e5e4;
            --surface: #fafaf9;
            --accent: #0f766e;
            --danger: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background: linear-gradient(180deg, #f5f5f4 0%, #e7e5e4 100%);
            min-height: 100vh;
            line-height: 1.5;
        }
        .shell {
            max-width: 720px;
            margin: 0 auto;
            padding: 2rem 1.25rem 3rem;
        }
        .card {
            background: #fff;
            border: 1px solid var(--line);
            padding: 1.75rem;
        }
        h1 { font-size: 1.75rem; margin: 0 0 0.35rem; }
        h2 {
            font-size: 1.05rem;
            margin: 1.5rem 0 0.5rem;
            border-bottom: 1px solid var(--line);
            padding-bottom: 0.25rem;
        }
        .muted { color: var(--muted); font-size: 0.95rem; }
        .meta { display: grid; gap: 0.35rem; margin: 1rem 0; }
        .meta div { display: flex; justify-content: space-between; gap: 1rem; }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        table.lines th,
        table.lines td {
            text-align: left;
            padding: 0.5rem 0.25rem;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }
        table.lines .num { text-align: right; white-space: nowrap; }
        .totals {
            margin-left: auto;
            width: min(100%, 260px);
            margin-top: 1rem;
        }
        .totals div {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.25rem 0;
        }
        .totals .grand {
            font-weight: 700;
            border-top: 1px solid var(--ink);
            margin-top: 0.35rem;
            padding-top: 0.5rem;
        }
        .terms {
            white-space: pre-wrap;
            background: var(--surface);
            padding: 1rem;
            border: 1px solid var(--line);
            font-size: 0.9rem;
        }
        .actions {
            display: grid;
            gap: 1.25rem;
            margin-top: 1.75rem;
        }
        form {
            display: grid;
            gap: 0.75rem;
            padding: 1rem;
            border: 1px solid var(--line);
            background: var(--surface);
        }
        label { display: grid; gap: 0.35rem; font-size: 0.9rem; }
        input[type="text"],
        textarea {
            font: inherit;
            padding: 0.55rem 0.65rem;
            border: 1px solid var(--line);
            width: 100%;
        }
        .check {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .check input { margin-top: 0.2rem; }
        button {
            font: inherit;
            padding: 0.65rem 1rem;
            border: 0;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
        }
        button.secondary {
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin: 1rem 0 0;
        }
        .toolbar a {
            color: var(--accent);
        }
        .errors {
            color: var(--danger);
            font-size: 0.9rem;
            margin: 0;
            padding-left: 1.1rem;
        }
        .banner {
            padding: 0.75rem 1rem;
            background: var(--surface);
            border: 1px solid var(--line);
            margin-bottom: 1rem;
        }
    </style>
    @yield('head')
</head>
<body>
    <main class="shell">
        @yield('content')
    </main>
</body>
</html>
