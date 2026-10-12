// watr.lol's own bits. watr.js does the heavy lifting, this just wires up the site
(function () {
    // the message from the last page (saved your profile, logged out...) as a toast
    const flash = document.getElementById("flash");
    if (flash && window.watr) {
        const type = flash.dataset.type || "info";
        watr.toast(flash.dataset.message, { type: type, time: type === "danger" ? 0 : 4000 });
    }

    // forms with data-confirm ask first, in the shared danger dialog. without js they just submit
    const dialog = document.getElementById("confirm");
    let pending = null;

    document.addEventListener("submit", function (e) {
        const form = e.target;
        if (!form.dataset.confirm || !dialog || !dialog.showModal || form.dataset.confirmed) return;

        e.preventDefault();
        pending = form;
        document.getElementById("confirm-title").textContent = form.dataset.confirmTitle || "are you sure?";
        document.getElementById("confirm-text").textContent = form.dataset.confirm;
        document.getElementById("confirm-button").textContent = form.dataset.confirmButton || "Delete";

        // the dropdown it came from shouldn't hang open behind the dialog
        const dropdown = form.closest("details[open]");
        if (dropdown) dropdown.open = false;

        dialog.returnValue = "";
        dialog.showModal();
    });

    if (dialog) {
        dialog.addEventListener("close", function () {
            if (dialog.returnValue === "yes" && pending) {
                pending.dataset.confirmed = "1";
                pending.requestSubmit ? pending.requestSubmit() : pending.submit();
            }
            pending = null;
        });
    }

    document.addEventListener("click", function (e) {
        // copy buttons on the developer pages
        const copy = e.target.closest("[data-copy]");
        if (copy) {
            const source = document.querySelector(copy.dataset.copy);
            if (source && navigator.clipboard) {
                navigator.clipboard.writeText(source.textContent.trim()).then(function () {
                    watr.toast("Copied.", { type: "plain", time: 2000 });
                });
            }
            return;
        }

        // "go back" on error pages
        if (e.target.closest("[data-back]")) {
            e.preventDefault();
            if (history.length > 1) history.back();
            else location.href = "/";
            return;
        }

        // click outside an open dropdown closes it
        document.querySelectorAll("details.watr-dropdown[open]").forEach(function (d) {
            if (!d.contains(e.target)) d.open = false;
        });
    });
})();
