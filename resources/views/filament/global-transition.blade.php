<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('pageTransition', () => ({
        show: false,

        init() {
            setTimeout(() => this.show = true, 30)

            document.addEventListener('livewire:navigated', () => {
                this.show = false
                requestAnimationFrame(() => {
                    this.show = true
                })
            })
        }
    }))
})
</script>

<div
    x-data="{ loading: false }"
    x-init="
        document.addEventListener('livewire:navigating', () => loading = true)
        document.addEventListener('livewire:navigated', () => loading = false)
    "
    x-show="loading"
    class="fixed top-0 left-0 w-full h-1 bg-primary-500 z-50 animate-pulse"
></div>

