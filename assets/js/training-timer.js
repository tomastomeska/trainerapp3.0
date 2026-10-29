document.addEventListener('DOMContentLoaded', function () {
    const timer = document.getElementById('trainingElapsedTimer');
    if (!timer) {
        return;
    }

    const startedAtMs = Number(timer.dataset.startedAtMs);
    const completed = timer.dataset.completed === '1';
    const savedDuration = Number(timer.dataset.durationCentiseconds || 0);
    const minutesInput = document.getElementById('duration-minutes');
    const secondsInput = document.getElementById('duration-seconds');

    function elapsedCentiseconds() {
        if (completed) {
            return savedDuration;
        }
        return Math.max(0, Math.floor((Date.now() - startedAtMs) / 10));
    }

    function formatDuration(centiseconds) {
        const minutes = Math.floor(centiseconds / 6000);
        const seconds = Math.floor((centiseconds % 6000) / 100);
        return minutes + ' min ' + seconds + ' s';
    }

    function updateTimer() {
        timer.textContent = formatDuration(elapsedCentiseconds());
    }

    function updateDurationInputs() {
        if (!minutesInput || !secondsInput) {
            return;
        }
        const centiseconds = elapsedCentiseconds();
        minutesInput.value = String(Math.floor(centiseconds / 6000));
        secondsInput.value = String(Math.floor((centiseconds % 6000) / 100));
    }

    updateTimer();
    if (!completed && Number.isFinite(startedAtMs) && startedAtMs > 0) {
        window.setInterval(updateTimer, 1000);
    }

    const completeModal = document.getElementById('completeModal');
    if (completeModal && !completed) {
        completeModal.addEventListener('show.bs.modal', updateDurationInputs);
    }
});
