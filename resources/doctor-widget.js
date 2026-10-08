/*
 * The Doctor tab: a button that runs pollora:doctor's web checks on demand
 * and lists what they found, errors first.
 */
(function () {
    if (! window.PhpDebugBar || PhpDebugBar.Widgets.PolloraDoctorWidget) {
        return;
    }

    const csscls = PhpDebugBar.utils.makecsscls('phpdebugbar-widgets-');

    class PolloraDoctorWidget extends PhpDebugBar.Widget {
        get className() {
            return csscls('pollora-doctor');
        }

        render() {
            const toolbar = document.createElement('div');
            toolbar.style.padding = '8px';

            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'Run doctor';

            const status = document.createElement('span');
            status.style.marginLeft = '8px';
            status.textContent = 'Runs pollora:doctor\'s web checks for this site.';

            const table = document.createElement('table');
            table.classList.add(csscls('tablevar'));
            table.style.width = '100%';

            toolbar.append(button, status);
            this.el.append(toolbar, table);

            button.addEventListener('click', async () => {
                const url = this.get('data')?.url;

                if (! url) {
                    return;
                }

                button.disabled = true;
                status.textContent = 'Running…';
                table.innerHTML = '';

                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    const text = await response.text();
                    const isJson = (response.headers.get('content-type') || '').includes('json');

                    // A proxy or a restarting server answers with plain text or
                    // HTML: say what came back rather than a JSON syntax error
                    if (! isJson) {
                        throw new Error('HTTP ' + response.status + ': ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120));
                    }

                    const body = JSON.parse(text);

                    if (! response.ok) {
                        throw new Error(body.message || 'HTTP ' + response.status);
                    }

                    const counts = {};

                    for (const check of body.checks) {
                        counts[check.status] = (counts[check.status] || 0) + 1;

                        const row = document.createElement('tr');
                        row.classList.add(csscls('item'));

                        const details = (check.details || []).join(' · ');

                        for (const value of [check.status, check.label, [check.summary, details, check.fix ? 'Fix: ' + check.fix : ''].filter(Boolean).join(' — ')]) {
                            const cell = document.createElement('td');
                            cell.classList.add(csscls('value'));
                            cell.textContent = value;
                            row.append(cell);
                        }

                        table.append(row);
                    }

                    status.textContent = Object.entries(counts).map(([name, count]) => count + ' ' + name).join(', ') || 'No web checks.';
                } catch (error) {
                    status.textContent = 'The doctor could not run: ' + error.message;
                } finally {
                    button.disabled = false;
                }
            });
        }
    }

    PhpDebugBar.Widgets.PolloraDoctorWidget = PolloraDoctorWidget;
})();
