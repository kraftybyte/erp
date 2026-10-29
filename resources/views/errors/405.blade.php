@extends('errors::minimal')

@section('title', 'Tidak bisa dibuka dengan cara ini')
@section('code', '405')
@section('message')
    Halaman ini tidak bisa dibuka langsung, atau halamannya sudah terlalu lama terbuka.
    <br><a href="{{ url('/admin') }}" style="text-decoration: underline;">Kembali ke ERP</a>, lalu ulangi langkahnya.
@endsection
