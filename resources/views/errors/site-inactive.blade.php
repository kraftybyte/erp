@extends('errors::minimal')

@section('title', 'ERP sedang diperbaiki')
@section('code', '503')
@section('message')
    ERP sedang dalam perbaikan dan untuk sementara tidak bisa dibuka. Coba lagi nanti.
    <form method="POST" action="{{ route('filament.admin.auth.logout') }}" style="margin-top: 1rem;">
        @csrf
        <button type="submit" style="text-decoration: underline; background: none; border: 0; padding: 0; color: inherit; font: inherit; cursor: pointer;">Keluar dan masuk dengan akun lain</button>
    </form>
@endsection
