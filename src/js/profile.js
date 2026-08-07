document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('avatar');
    var label = document.getElementById('file-name');
    if (!input || !label) return;
    input.addEventListener('change', function() {
        label.textContent = this.files[0] ? this.files[0].name : 'No file chosen';
    });
});
