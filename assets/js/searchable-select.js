/** Enhance the assignment select with one dropdown containing its own search. */
function eventadminSearchableSelect(select) {
    const root = document.createElement('div');
    root.className = 'eventadmin-search-select';
    const trigger = document.createElement('button');
    trigger.type = 'button'; trigger.className = 'button eventadmin-search-select-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
    const popup = document.createElement('div'); popup.className = 'eventadmin-search-select-popup'; popup.hidden = true;
    const search = document.createElement('input'); search.type = 'search'; search.autocomplete = 'off';
    search.placeholder = select.dataset.searchLabel; search.setAttribute('aria-label', select.dataset.searchLabel);
    search.setAttribute('role', 'combobox'); search.setAttribute('aria-autocomplete', 'list'); search.setAttribute('aria-expanded', 'false');
    const list = document.createElement('div'); list.id = select.id + '-options'; list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', select.getAttribute('aria-label'));
    search.setAttribute('aria-controls', list.id); trigger.setAttribute('aria-controls', list.id);
    const empty = document.createElement('p'); empty.textContent = select.dataset.emptyLabel; empty.setAttribute('role', 'status');
    popup.append(search, list, empty); root.append(trigger, popup); select.after(root);
    const required = select.required;
    select.hidden = true; select.required = false;
    let active = -1;
    let options = [];
    function highlight(index) {
        active = index;
        [...list.children].forEach((item, i) => item.setAttribute('aria-selected', String(i === active)));
        if (list.children[active]) {
            search.setAttribute('aria-activedescendant', list.children[active].id);
            list.children[active].scrollIntoView({block: 'nearest'});
        } else search.removeAttribute('aria-activedescendant');
    }
    function close(returnFocus = false) {
        popup.hidden = true; trigger.setAttribute('aria-expanded', 'false'); search.setAttribute('aria-expanded', 'false');
        if (returnFocus) trigger.focus();
    }
    function refresh() {
        close(); search.value = '';
        trigger.textContent = select.selectedOptions[0]?.textContent || select.options[0].textContent;
        trigger.removeAttribute('aria-invalid');
    }
    function choose(index) {
        if (!options[index]) return;
        select.value = options[index].value; select.dispatchEvent(new Event('change', {bubbles: true}));
        refresh(); trigger.focus();
    }
    function filter() {
        const query = search.value.trim().toLocaleLowerCase();
        options = [...select.options].filter(option => option.value && !option.disabled && option.textContent.toLocaleLowerCase().includes(query));
        list.replaceChildren();
        options.forEach((option, index) => {
            const item = document.createElement('div'); item.id = list.id + '-' + index; item.setAttribute('role', 'option');
            item.textContent = option.textContent;
            item.addEventListener('click', () => choose(index)); list.append(item);
        });
        empty.hidden = options.length > 0;
        highlight(options.length ? 0 : -1);
    }
    function open() {
        popup.hidden = false; trigger.setAttribute('aria-expanded', 'true'); search.setAttribute('aria-expanded', 'true');
        search.value = ''; filter(); search.focus();
    }
    trigger.addEventListener('click', () => popup.hidden ? open() : close());
    trigger.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); open(); }
    });
    search.addEventListener('input', filter);
    root.addEventListener('keydown', event => {
        if (popup.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
        else if (event.key === 'Tab') close();
        else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (options.length) highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
        } else if (event.key === 'Enter' && event.target === search) { event.preventDefault(); choose(active); }
    });
    document.addEventListener('click', event => { if (!root.contains(event.target)) close(); });
    if (required) select.form?.addEventListener('submit', event => {
        if (!select.value) { event.preventDefault(); trigger.setAttribute('aria-invalid', 'true'); open(); }
    });
    refresh();
    return {refresh};
}

