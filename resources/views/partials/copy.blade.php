{{-- Copie d'un lien dans le presse-papier, partagée par toutes les pages.

     navigator.clipboard n'existe que sur une origine sûre (https, ou localhost) :
     sur une adresse IP locale en http — un enseignant qui teste depuis son
     téléphone, par exemple — on retombe sur une sélection temporaire, qui
     fonctionne partout.

     Le retour visuel se fait sur le libellé du bouton (l'icône ne bouge pas). --}}
<script>
    function copyText(text, button) {
        const done = () => {
            if (!button) {
                return;
            }

            // Un bouton peut porter deux libellés (un pour le mobile, un pour
            // l'écran large) : les deux sont mis à jour.
            const labels = button.querySelectorAll('[data-copy-label]');

            labels.forEach((label) => {
                label.dataset.original = label.dataset.original || label.textContent;
                label.textContent = 'Copié !';
            });

            setTimeout(() => {
                labels.forEach((label) => { label.textContent = label.dataset.original; });
            }, 1800);
        };

        if (window.navigator.clipboard && window.isSecureContext) {
            window.navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));

            return;
        }

        fallbackCopy(text, done);
    }

    function copyField(selector, button) {
        const field = document.querySelector(selector);

        if (field) {
            copyText(field.value, button);
        }
    }

    function fallbackCopy(text, done) {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.top = '-1000px';

        document.body.appendChild(area);
        area.select();

        try {
            document.execCommand('copy');
            done();
        } catch (error) {
            window.prompt('Copiez ce lien :', text);
        }

        document.body.removeChild(area);
    }
</script>
