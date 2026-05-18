import preset from '../../../../vendor/filament/filament/tailwind.config.preset'
import colors from 'tailwindcss/colors'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Sora', ...defaultTheme.fontFamily.sans],
            colors: {
                ...colors,
            },
        },
    },
}
