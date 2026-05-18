import preset from './vendor/filament/support/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './app/Filament/**/*.php',
        './vendor/filament/**/*.blade.php',
    ],

   safelist: [
    {
        pattern: /(bg|text|border)-(red|orange|yellow|green|blue|purple|pink|teal|gray)-(50|100|200|300|400|500|600|700|800|900)/,
    },
],

    theme: {
        extend: {
        },
    },
    plugins: [
  ],
}
