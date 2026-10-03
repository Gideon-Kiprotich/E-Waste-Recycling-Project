<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main>
            <p class="location">Kenya · Nairobi County</p>
            <h1>Smart E-Waste Collection, Tracking and Recycling Management Platform</h1>
            <p>A university final-year project focused on responsible electronic waste management in Kenya, with Nairobi County as the case study.</p>
        </main>
    </body>
</html>
