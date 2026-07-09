import { jsx as _jsx, jsxs as _jsxs } from "react/jsx-runtime";
import { useEffect, useRef, useState } from 'react';
const inputClass = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors ' +
    'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring ' +
    'disabled:cursor-not-allowed disabled:opacity-50';
function TextWidget({ field, value, onChange, onBlur, disabled }) {
    const type = field.type === 'email' ? 'email' : field.type === 'password' ? 'password' : 'text';
    return (_jsx("input", { type: type, className: inputClass, value: value ?? '', onChange: (e) => onChange(e.target.value), onBlur: onBlur, disabled: disabled, autoComplete: field.type === 'password' ? 'new-password' : undefined }));
}
function NumberWidget({ value, onChange, onBlur, disabled }) {
    return (_jsx("input", { type: "number", className: inputClass, value: value === undefined || value === null ? '' : String(value), onChange: (e) => onChange(e.target.value === '' ? undefined : Number(e.target.value)), onBlur: onBlur, disabled: disabled }));
}
function TextareaWidget({ value, onChange, onBlur, disabled }) {
    return (_jsx("textarea", { className: inputClass + ' h-auto min-h-20', rows: 3, value: value ?? '', onChange: (e) => onChange(e.target.value), onBlur: onBlur, disabled: disabled }));
}
// Wire-aware truthiness: legacy `on_off` boolean fields deliver 'on'/'off'
// strings, and 'off' is truthy to Boolean() — map the known wire encodings
// explicitly instead. Submission stays a real boolean (the contract's boolean
// type); the server converts back to the wire format.
function isCheckedValue(value) {
    if (typeof value === 'string') {
        return value === 'on' || value === '1' || value === 'true';
    }
    return value === true || value === 1;
}
function CheckboxWidget({ field, value, onChange, disabled }) {
    return (_jsxs("label", { className: "flex items-center gap-2 text-sm", children: [_jsx("input", { type: "checkbox", className: "h-4 w-4 rounded border-input accent-primary", checked: isCheckedValue(value), onChange: (e) => onChange(e.target.checked), disabled: disabled }), _jsx("span", { className: "text-muted-foreground", children: field.label })] }));
}
function SelectWidget({ field, value, onChange, onBlur, disabled }) {
    return (_jsxs("select", { className: inputClass, value: value === undefined || value === null ? '' : String(value), onChange: (e) => onChange(e.target.value === '' ? undefined : e.target.value), onBlur: onBlur, disabled: disabled, children: [_jsx("option", { value: "", children: "\u2014 select \u2014" }), (field.options ?? []).map((option) => (_jsx("option", { value: String(option.value), children: option.label }, String(option.value))))] }));
}
function DateWidget({ field, value, onChange, onBlur, disabled }) {
    return (_jsx("input", { type: field.type === 'datetime' ? 'datetime-local' : 'date', className: inputClass, value: value ?? '', onChange: (e) => onChange(e.target.value), onBlur: onBlur, disabled: disabled }));
}
/**
 * Async searchable select for relation fields, backed by the options
 * endpoint (debounced q search, load-more pagination).
 */
function ComboboxWidget({ field, value, onChange, disabled, client, subject }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState([]);
    const [nextPage, setNextPage] = useState(null);
    const [selectedLabel, setSelectedLabel] = useState(null);
    const rootRef = useRef(null);
    const timer = useRef();
    const load = async (q, page = 1, append = false) => {
        if (!client || !subject)
            return;
        const result = await client.options(subject, field.name, { q: q || undefined, page });
        setOptions((prev) => (append ? [...prev, ...result.options] : result.options));
        setNextPage(result.next_page);
    };
    useEffect(() => {
        if (!open)
            return;
        clearTimeout(timer.current);
        timer.current = setTimeout(() => void load(query), query ? 200 : 0);
        return () => clearTimeout(timer.current);
    }, [open, query]);
    useEffect(() => {
        const close = (e) => {
            if (rootRef.current && !rootRef.current.contains(e.target))
                setOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);
    const current = selectedLabel ?? (value !== undefined && value !== null ? `#${value}` : null);
    return (_jsxs("div", { ref: rootRef, className: "relative", children: [_jsx("button", { type: "button", className: inputClass + ' justify-between text-left', onClick: () => setOpen((o) => !o), disabled: disabled, children: _jsx("span", { className: current ? '' : 'text-muted-foreground', children: current ?? 'Select…' }) }), open && (_jsxs("div", { className: "absolute z-10 mt-1 w-full rounded-md border border-input bg-popover p-1 shadow-md", children: [field.relation?.searchable && (_jsx("input", { autoFocus: true, className: inputClass + ' mb-1', placeholder: "Search\u2026", value: query, onChange: (e) => setQuery(e.target.value) })), _jsxs("ul", { className: "max-h-48 overflow-auto text-sm", children: [options.length === 0 && _jsx("li", { className: "px-2 py-1.5 text-muted-foreground", children: "No results" }), options.map((option) => (_jsx("li", { children: _jsx("button", { type: "button", className: "w-full rounded-sm px-2 py-1.5 text-left hover:bg-accent", onClick: () => {
                                        onChange(option.value);
                                        setSelectedLabel(option.label);
                                        setOpen(false);
                                    }, children: option.label }) }, String(option.value)))), nextPage !== null && (_jsx("li", { children: _jsx("button", { type: "button", className: "w-full rounded-sm px-2 py-1.5 text-left text-muted-foreground hover:bg-accent", onClick: () => void load(query, nextPage, true), children: "Load more\u2026" }) }))] })] }))] }));
}
export const defaultWidgets = {
    input: TextWidget,
    password: TextWidget,
    textarea: TextareaWidget,
    number: NumberWidget,
    checkbox: CheckboxWidget,
    switch: CheckboxWidget,
    select: SelectWidget,
    combobox: ComboboxWidget,
    datepicker: DateWidget,
    datetimepicker: DateWidget,
};
