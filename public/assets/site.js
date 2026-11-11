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

    // reactions toggle in the background and redraw their row. without js the form posts and comes back
    document.addEventListener("submit", function (e) {
        const form = e.target;
        if (!form.hasAttribute("data-react") || !window.fetch || !e.submitter) return;
        e.preventDefault();

        const data = new FormData(form);
        data.set("emoji", e.submitter.value);
        const picker = form.querySelector("details[open]");
        if (picker) picker.open = false;

        fetch(form.action, { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.body.reactions) throw new Error(res.body.error || "failed");
                drawReactions(form, res.body.reactions);
            })
            .catch(function (err) {
                if (window.watr) watr.toast(err.message === "failed" ? "Couldn't react. Try again." : err.message, { type: "warning", time: 3000 });
            });
    });

    function drawReactions(form, reactions) {
        form.querySelectorAll(":scope > button.react").forEach(function (b) { b.remove(); });
        const picker = form.querySelector(".react-pick");
        Object.keys(reactions).forEach(function (emoji) {
            const r = reactions[emoji];
            const b = document.createElement("button");
            b.type = "submit";
            b.name = "emoji";
            b.value = emoji;
            b.title = emoji;
            b.className = "react" + (r.mine ? " is-mine" : "");
            b.setAttribute("aria-pressed", r.mine ? "true" : "false");
            const pickImg = form.querySelector('.react-menu button[value="' + emoji + '"] img');
            const img = document.createElement("img");
            img.src = pickImg ? pickImg.src : "";
            img.alt = emoji;
            img.width = 20;
            img.height = 20;
            const n = document.createElement("span");
            n.textContent = r.count;
            b.append(img, n);
            form.insertBefore(b, picker);
        });
    }

    // "500 left" under the update box
    document.querySelectorAll("textarea[data-count]").forEach(function (box) {
        const out = document.getElementById(box.dataset.count);
        const max = parseInt(box.getAttribute("maxlength"), 10) || 500;
        if (!out) return;
        const draw = function () {
            const left = max - box.value.length;
            out.textContent = left + " left";
            out.classList.toggle("is-over", left < 20);
        };
        box.addEventListener("input", draw);
        draw();
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
