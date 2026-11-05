import { Controller } from '@hotwired/stimulus';

const WITHOUT_PLAYLIST = 'any';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['account', 'playlists', 'create'];
    static values = { channel: String };

    connect() {
        this.toggle();
    }

    toggle() {
        const channel = document.getElementById(this.channelValue);
        this.element.closest('#youtube_advanced_configuration')?.classList.toggle('pmk-display-none', !channel?.checked);
    }

    changeAccount() {
        if (this.hasCreateTarget) {
            this.createTarget.setAttribute('href', this.createTarget.dataset.url.replace('__account__', this.accountTarget.value));
        }
        this.loadPlaylists([]);
    }

    refresh(event) {
        event.preventDefault();
        this.loadPlaylists(Array.from(this.playlistsTarget.selectedOptions, (option) => option.value));
    }

    changePlaylists() {
        const options = Array.from(this.playlistsTarget.options);
        const withoutPlaylist = options.find((option) => WITHOUT_PLAYLIST === option.value);
        if (!withoutPlaylist) {
            return;
        }
        const others = options.some((option) => option.selected && option !== withoutPlaylist);
        withoutPlaylist.selected = !others;
    }

    loadPlaylists(selected) {
        const { url } = this.accountTarget.selectedOptions[0].dataset;
        $.ajax({ url, method: 'GET' })
            .done((playlists) => {
                this.playlistsTarget.replaceChildren(...playlists.map(({ id, text }) => new Option(text, id, false, selected.includes(id))));
                this.changePlaylists();
            })
            .fail((xhr, textStatus, errorThrown) => window.no_success(`${errorThrown}: ${textStatus}`));
    }
}
