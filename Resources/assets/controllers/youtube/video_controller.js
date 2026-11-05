import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    run(event) {
        event.preventDefault();
        const { url, confirm, success, error } = event.params;
        if (confirm && !window.confirm(confirm)) {
            return;
        }
        $('#myModal').modal('hide');
        $.ajax({ url, method: 'GET' })
            .done((response) => {
                if (response?.error) {
                    window.no_success(response.error);
                } else if (false === response) {
                    window.no_success(error);
                } else {
                    window.success(success);
                }
            })
            .fail(() => window.no_success(error));
    }
}
