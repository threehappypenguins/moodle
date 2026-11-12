// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Homeschool day page interactions.
 *
 * @module     local_homeschool/day
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bind day picker, multi-select, autosave, and expand/collapse behaviour.
 */
export const init = () => {
    const daySelect = document.getElementById('local-homeschool-day');
    if (daySelect) {
        daySelect.addEventListener('change', () => {
            daySelect.form.submit();
        });
    }

    const showAll = document.getElementById('local-homeschool-showall');
    if (showAll) {
        showAll.addEventListener('change', () => {
            showAll.form.submit();
        });
    }

    const root = document.querySelector('.local-homeschool-day');
    if (!root) {
        return;
    }

    const syncBulkDeleteFromSelection = () => {
        const bulkDelete = root.querySelector('[data-action="local-homeschool-delete-selected"]');
        if (!bulkDelete) {
            return;
        }
        bulkDelete.disabled = root.querySelectorAll('.local-homeschool-select-cm:checked').length === 0;
    };

    const setMultiselectLock = (container, locked) => {
        if (!container) {
            return;
        }
        if (locked) {
            container.setAttribute('inert', '');
            container.querySelectorAll('input, select, textarea, button, fieldset').forEach((el) => {
                if (el.type === 'hidden') {
                    return;
                }
                if (el.disabled) {
                    el.setAttribute('data-multiselect-was-disabled', '1');
                    return;
                }
                el.disabled = true;
                el.setAttribute('data-multiselect-disabled', '1');
            });
            return;
        }
        container.removeAttribute('inert');
        container.querySelectorAll('[data-multiselect-disabled]').forEach((el) => {
            el.disabled = false;
            el.removeAttribute('data-multiselect-disabled');
        });
        container.querySelectorAll('[data-multiselect-was-disabled]').forEach((el) => {
            el.removeAttribute('data-multiselect-was-disabled');
        });
    };

    const updateRowEditing = () => {
        const selected = root.querySelectorAll('.local-homeschool-select-cm:checked').length;
        const multi = selected >= 2;
        root.classList.toggle('local-homeschool-multiselect', multi);
        const hint = root.querySelector('.local-homeschool-multiselect-hint');
        if (hint) {
            hint.hidden = !multi;
        }
        root.querySelectorAll('.local-homeschool-activity').forEach((row) => {
            const checkbox = row.querySelector('.local-homeschool-select-cm');
            const isselected = !!(checkbox && checkbox.checked);
            row.classList.toggle('is-selected', isselected);
            setMultiselectLock(row.querySelector('.local-homeschool-activity-details'), multi && isselected);
        });
        syncBulkDeleteFromSelection();
    };

    const syncDateEditable = (row, editable) => {
        if (!row) {
            return;
        }
        const dateForm = row.querySelector('.local-homeschool-row-date');
        if (!dateForm) {
            return;
        }
        dateForm.classList.toggle('is-disabled', !editable);
        if (editable) {
            dateForm.removeAttribute('title');
        } else {
            dateForm.setAttribute('title', dateForm.getAttribute('data-disabled-title') || '');
        }
        dateForm.querySelectorAll('input, button').forEach((el) => {
            if (el.type === 'hidden') {
                return;
            }
            el.disabled = !editable;
        });
    };

    const syncRequirementExtras = (form) => {
        const gradeReq = form.querySelector('.local-homeschool-requirement[data-requirement="completionusegrade"]');
        const passgrade = form.querySelector('.local-homeschool-passgrade');
        if (gradeReq && passgrade) {
            passgrade.hidden = !gradeReq.checked;
        }
        const passSelected = form.querySelector('.local-homeschool-passgrade-radio:checked');
        const exhausted = form.querySelector('.local-homeschool-exhausted');
        if (exhausted) {
            exhausted.hidden = !(gradeReq && gradeReq.checked && passSelected && passSelected.value === '1');
        }
        form.querySelectorAll('.local-homeschool-requirement[data-requirement-type="int"]').forEach((cb) => {
            const valueInput = form.querySelector(
                '[data-requirement-value-for="' + cb.getAttribute('data-requirement') + '"]'
            );
            if (valueInput) {
                valueInput.hidden = !cb.checked;
                if (cb.checked && (!valueInput.value || parseInt(valueInput.value, 10) < 1)) {
                    valueInput.value = '1';
                }
            }
        });
    };

    root.querySelectorAll('.local-homeschool-row-date.is-disabled').forEach((dateForm) => {
        dateForm.setAttribute('data-disabled-title', dateForm.getAttribute('title') || '');
    });

    /**
     * @param {HTMLFormElement} form
     * @return {{selectedCompletion: HTMLInputElement|null, automaticSelected: boolean}}
     */
    const getCompletionState = (form) => {
        const selectedCompletion = form.querySelector('input[name="completion"]:checked');
        if (selectedCompletion) {
            return {
                selectedCompletion,
                automaticSelected: selectedCompletion.value === '2',
            };
        }
        const hiddenCompletion = form.querySelector('input[name="completion"][type="hidden"]');
        return {
            selectedCompletion: null,
            automaticSelected: !!(hiddenCompletion && hiddenCompletion.value === '2'),
        };
    };

    /**
     * @param {HTMLFormElement} form
     * @param {HTMLInputElement} completionRadio
     * @param {boolean} automaticSelected
     * @param {HTMLInputElement|null} selectedCompletion
     */
    const applyCompletionRadioChange = (form, completionRadio, automaticSelected, selectedCompletion) => {
        const summaryText = form.querySelector('.local-homeschool-completion-summary-text');
        if (summaryText) {
            const label = completionRadio.closest('label');
            const labelSpan = label ? label.querySelector('span') : null;
            if (labelSpan) {
                summaryText.textContent = labelSpan.textContent;
            }
        }
        const picker = form.querySelector('.local-homeschool-completion-picker');
        if (picker) {
            picker.open = false;
        }
        const requirements = form.querySelector('.local-homeschool-requirements');
        if (requirements) {
            requirements.hidden = !automaticSelected;
            if (automaticSelected) {
                requirements.open = true;
            }
        }
        const activityRow = form.closest('.local-homeschool-activity');
        syncDateEditable(activityRow, selectedCompletion && selectedCompletion.value !== '0');
    };

    /**
     * @param {HTMLFormElement} form
     * @param {boolean} automaticSelected
     * @return {boolean} False when autosave should abort.
     */
    const validateAutomaticRequirements = (form, automaticSelected) => {
        const requirements = form.querySelector('.local-homeschool-requirements');
        if (!automaticSelected || !requirements) {
            return true;
        }
        const anyRequirement = form.querySelectorAll('.local-homeschool-requirement:checked').length > 0;
        const error = requirements.querySelector('.local-homeschool-requirements-error');
        if (!anyRequirement) {
            requirements.hidden = false;
            requirements.open = true;
            if (error) {
                error.hidden = false;
            }
            return false;
        }
        if (error) {
            error.hidden = true;
        }
        return true;
    };

    /**
     * @param {EventTarget} target
     * @param {string} cmid
     * @param {boolean} automaticSelected
     */
    const rememberExpandedPanels = (target, cmid, automaticSelected) => {
        if (!cmid) {
            return;
        }
        if (!(target.classList.contains('local-homeschool-autosave') ||
                target.classList.contains('local-homeschool-requirement-value'))) {
            return;
        }
        if (target.closest('.local-homeschool-activity-details')) {
            sessionStorage.setItem('local_homeschool_expand_activity', cmid);
        }
        if (target.closest('.local-homeschool-submissions')) {
            sessionStorage.setItem('local_homeschool_expand_submissions', cmid);
        }
        if (target.closest('.local-homeschool-requirements') || automaticSelected) {
            sessionStorage.setItem('local_homeschool_expand_requirements', cmid);
        }
    };

    /**
     * @param {Event} event
     */
    const handleAutosaveChange = (event) => {
        const form = event.target.closest('form');
        if (!form) {
            return;
        }

        const cmidInput = form.querySelector('input[name="cmid"]');
        const cmid = cmidInput ? cmidInput.value : '';
        const completionRadio = event.target.classList.contains('local-homeschool-completion-radio')
            ? event.target
            : null;
        const {selectedCompletion, automaticSelected} = getCompletionState(form);

        if (completionRadio) {
            applyCompletionRadioChange(form, completionRadio, automaticSelected, selectedCompletion);
        }

        if (event.target.classList.contains('local-homeschool-requirement') ||
                event.target.classList.contains('local-homeschool-passgrade-radio')) {
            syncRequirementExtras(form);
        }

        if (!validateAutomaticRequirements(form, automaticSelected)) {
            return;
        }

        rememberExpandedPanels(event.target, cmid, automaticSelected);
        form.submit();
    };

    root.addEventListener('change', (event) => {
        if (event.target.classList.contains('local-homeschool-select-cm') ||
                event.target.id === 'local-homeschool-selectall') {
            if (event.target.id === 'local-homeschool-selectall') {
                const checked = event.target.checked;
                root.querySelectorAll('.local-homeschool-select-cm').forEach((cb) => {
                    cb.checked = checked;
                });
            }
            updateRowEditing();
            return;
        }

        if (root.classList.contains('local-homeschool-multiselect')) {
            const selectedRow = event.target.closest('.local-homeschool-activity.is-selected');
            // Settings on selected rows are locked while multi-selecting; dates stay editable.
            if (selectedRow && !event.target.classList.contains('local-homeschool-date-input')) {
                return;
            }
        }

        if (event.target.classList.contains('local-homeschool-autosave') ||
                event.target.classList.contains('local-homeschool-date-input') ||
                event.target.classList.contains('local-homeschool-requirement-value')) {
            handleAutosaveChange(event);
        }
    });
    root.addEventListener('click', (event) => {
        const selectAll = event.target.closest('[data-action="local-homeschool-selectall"]');
        const deselectAll = event.target.closest('[data-action="local-homeschool-deselectall"]');
        if (selectAll || deselectAll) {
            event.preventDefault();
            const checked = !!selectAll;
            root.querySelectorAll('.local-homeschool-select-cm').forEach((cb) => {
                cb.checked = checked;
            });
            const master = document.getElementById('local-homeschool-selectall');
            if (master) {
                master.checked = checked;
            }
            updateRowEditing();
            return;
        }

        const dateInput = event.target.closest('.local-homeschool-date-input');
        if (dateInput && typeof dateInput.showPicker === 'function') {
            try {
                dateInput.showPicker();
            } catch (e) {
                // Browser may reject showPicker outside a direct gesture; native click still works.
            }
        }
    });

    const dateForm = document.getElementById('local-homeschool-bulk-date-form');
    if (dateForm) {
        dateForm.addEventListener('submit', () => {
            dateForm.querySelectorAll('input[name="cmids[]"]').forEach((el) => {
                if (el.classList.contains('local-homeschool-select-cm')) {
                    return;
                }
                el.remove();
            });
            root.querySelectorAll('.local-homeschool-select-cm:checked').forEach((cb) => {
                if (cb.form === dateForm) {
                    return;
                }
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cmids[]';
                input.value = cb.value;
                dateForm.appendChild(input);
            });
        });
    }

    const expandActivity = sessionStorage.getItem('local_homeschool_expand_activity');
    const expandSubmissions = sessionStorage.getItem('local_homeschool_expand_submissions');
    const expandRequirements = sessionStorage.getItem('local_homeschool_expand_requirements');
    sessionStorage.removeItem('local_homeschool_expand_activity');
    sessionStorage.removeItem('local_homeschool_expand_submissions');
    sessionStorage.removeItem('local_homeschool_expand_requirements');

    const desktopQuery = window.matchMedia('(min-width: 768px)');
    const syncActivityDetails = () => {
        root.querySelectorAll('.local-homeschool-activity-details').forEach((details) => {
            const keepOpen = expandActivity && details.getAttribute('data-cmid') === expandActivity;
            details.open = desktopQuery.matches || !!keepOpen;
        });
        if (expandSubmissions) {
            const submissions = root.querySelector(
                '.local-homeschool-submissions[data-cmid="' + expandSubmissions + '"]'
            );
            if (submissions) {
                submissions.open = true;
            }
        }
        if (expandRequirements) {
            const requirementsPanel = root.querySelector(
                '.local-homeschool-requirements[data-cmid="' + expandRequirements + '"]'
            );
            if (requirementsPanel) {
                requirementsPanel.hidden = false;
                requirementsPanel.open = true;
            }
        }
    };
    syncActivityDetails();
    root.querySelectorAll('.local-homeschool-row-edit').forEach(syncRequirementExtras);
    if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener('change', syncActivityDetails);
    } else if (desktopQuery.addListener) {
        desktopQuery.addListener(syncActivityDetails);
    }

    updateRowEditing();
};
