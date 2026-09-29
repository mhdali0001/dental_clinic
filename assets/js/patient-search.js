// بحث عن مريض بالاسم أو رقم الهاتف فوق قائمة <select> عادية.
// القائمة الأصلية تبقى (مخفية) مصدرَ القيمة المرسلة مع النموذج، ويُطلق عليها حدث change عند الاختيار،
// لذلك يستمر عمل أي كود مرتبط بها (onchange، required).
//
// الاستخدام:
//   const search = PatientSearch.attach(document.getElementById('patientSelect'), { inputId: 'patientSearchInput' });
//   search.sync();  // بعد تغيير select.value برمجياً دون إطلاق change
//
// بيانات المريض تُقرأ من خصائص <option>: data-name و data-phone و data-age (وإلا نص الخيار).
(function (window, document) {
    const MAX_RESULTS = 50;

    function injectStyles() {
        if (document.getElementById('patient-search-styles')) return;
        const style = document.createElement('style');
        style.id = 'patient-search-styles';
        style.textContent =
            '.patient-search-native{position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;pointer-events:none;}' +
            '.patient-search-option.active{background-color:#eff6ff;}';
        document.head.appendChild(style);
    }

    // يطابق "احمد" مع "أحمد"، و"فاطمه" مع "فاطمة"، والأرقام العربية مع اللاتينية
    function normalize(text) {
        return (text || '')
            .toString()
            .toLowerCase()
            .replace(/[ً-ْـ]/g, '') // التشكيل والتطويل
            .replace(/[أإآٱ]/g, 'ا')
            .replace(/ة/g, 'ه')
            .replace(/ى/g, 'ي')
            .replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))
            .replace(/\s+/g, ' ')
            .trim();
    }

    function optionName(option) {
        return option.dataset.name || option.textContent.trim().replace(/\s+/g, ' ');
    }

    function optionLabel(option) {
        const phone = option.dataset.phone ? ' - ' + option.dataset.phone : '';
        return optionName(option) + (option.dataset.name ? phone : '');
    }

    function attach(select, options = {}) {
        if (!select) return null;
        injectStyles();

        const settings = Object.assign({
            inputId: '',
            placeholder: 'ابحث باسم المريض أو رقم الهاتف...',
            inputClass: 'w-full p-3 pr-10 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500'
        }, options);

        // ---- markup: [wrapper [input-row [icon][input][clear]] [select (hidden)] [dropdown]] ----
        const wrapper = document.createElement('div');
        wrapper.className = 'relative';
        select.parentNode.insertBefore(wrapper, select);

        const inputRow = document.createElement('div');
        inputRow.className = 'relative';
        inputRow.innerHTML =
            '<i class="fas fa-search absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none"></i>' +
            '<input type="text" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false">' +
            '<button type="button" title="مسح اختيار المريض" class="hidden absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-red-500">' +
            '<i class="fas fa-times"></i></button>';
        const input = inputRow.querySelector('input');
        const clearBtn = inputRow.querySelector('button');
        input.className = settings.inputClass;
        input.placeholder = settings.placeholder;
        if (settings.inputId) input.id = settings.inputId;

        const dropdown = document.createElement('ul');
        dropdown.setAttribute('role', 'listbox');
        dropdown.className = 'hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-72 overflow-y-auto';
        dropdown.id = (select.id || 'patient') + 'SearchDropdown';
        input.setAttribute('aria-controls', dropdown.id);

        wrapper.appendChild(inputRow);
        wrapper.appendChild(select);
        wrapper.appendChild(dropdown);
        select.classList.add('patient-search-native');
        select.tabIndex = -1;

        const patients = Array.from(select.options)
            .filter(option => option.value)
            .map(option => ({
                id: option.value,
                name: optionName(option),
                phone: option.dataset.phone || '',
                age: option.dataset.age || '',
                haystack: normalize(optionName(option) + ' ' + (option.dataset.phone || ''))
            }));

        let results = [];
        let activeIndex = -1;

        function sync() {
            const selected = select.value ? select.options[select.selectedIndex] : null;
            input.value = selected ? optionLabel(selected) : '';
            clearBtn.classList.toggle('hidden', !selected);
        }

        function open() {
            dropdown.classList.remove('hidden');
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            dropdown.classList.add('hidden');
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function setActive(index) {
            const items = dropdown.querySelectorAll('.patient-search-option');
            items.forEach(item => item.classList.remove('active'));
            activeIndex = index;
            if (items[index]) {
                items[index].classList.add('active');
                items[index].scrollIntoView({ block: 'nearest' });
            }
        }

        function setValue(value) {
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            sync();
        }

        function choose(index) {
            const patient = results[index];
            if (!patient) return;
            close();
            setValue(patient.id);
        }

        function render(term) {
            const words = normalize(term).split(' ').filter(Boolean);
            const matches = words.length ? patients.filter(p => words.every(w => p.haystack.includes(w))) : patients;
            results = matches.slice(0, MAX_RESULTS);

            dropdown.innerHTML = '';
            if (results.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'px-4 py-3 text-sm text-gray-500 text-center';
                empty.textContent = 'لا يوجد مريض مطابق للبحث';
                dropdown.appendChild(empty);
            } else {
                results.forEach((patient, index) => {
                    const li = document.createElement('li');
                    li.className = 'patient-search-option px-4 py-2 cursor-pointer hover:bg-blue-50 border-b border-gray-100';
                    li.setAttribute('role', 'option');
                    if (patient.id === select.value) li.setAttribute('aria-selected', 'true');

                    const nameEl = document.createElement('div');
                    nameEl.className = 'font-semibold text-gray-800';
                    nameEl.textContent = patient.name;
                    const metaEl = document.createElement('div');
                    metaEl.className = 'text-sm text-gray-500';
                    metaEl.textContent = [patient.phone, patient.age ? patient.age + ' سنة' : ''].filter(Boolean).join(' · ');

                    li.appendChild(nameEl);
                    li.appendChild(metaEl);
                    // mousedown (not click) so the input's blur doesn't close the list first
                    li.addEventListener('mousedown', e => { e.preventDefault(); choose(index); });
                    li.addEventListener('mousemove', () => { if (activeIndex !== index) setActive(index); });
                    dropdown.appendChild(li);
                });

                if (matches.length > MAX_RESULTS) {
                    const more = document.createElement('li');
                    more.className = 'px-4 py-2 text-xs text-gray-400 text-center';
                    more.textContent = 'يوجد ' + (matches.length - MAX_RESULTS) + ' نتيجة أخرى، اكتب المزيد لتضييق البحث';
                    dropdown.appendChild(more);
                }
            }

            open();
            if (results.length) setActive(words.length ? 0 : -1);
        }

        input.addEventListener('focus', () => {
            input.select();
            render(select.value ? '' : input.value);
        });
        input.addEventListener('input', () => render(input.value));
        input.addEventListener('keydown', e => {
            const isOpen = !dropdown.classList.contains('hidden');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) render(input.value);
                else if (results.length) setActive(Math.min(activeIndex + 1, results.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (isOpen && results.length) setActive(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                e.preventDefault(); // never submit the surrounding form from the search box
                if (isOpen) choose(activeIndex >= 0 ? activeIndex : (results.length === 1 ? 0 : -1));
            } else if (e.key === 'Escape') {
                close();
                sync();
            }
        });
        // Leaving the field without picking restores the current selection's label
        input.addEventListener('blur', () => { close(); sync(); });
        clearBtn.addEventListener('click', () => { setValue(''); input.focus(); });
        // Keep the label right when other code changes the select and fires change
        select.addEventListener('change', sync);

        sync();
        return { sync, input, select };
    }

    window.PatientSearch = { attach, normalize };
})(window, document);
