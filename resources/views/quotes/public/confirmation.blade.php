@extends('quotes.public.layout')

@section('title', 'Quote response recorded')

@section('content')
    <article class="card">
        <h1>{{ $outcome === 'accepted' ? 'Quote accepted' : 'Response recorded' }}</h1>
        <p>{{ $message }}</p>
        <p class="muted">You can close this page.</p>
    </article>
@endsection
