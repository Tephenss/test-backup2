document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('rfidManagerApp');
    if (!app) return;

    const state = window.RfidManagerState || { tags: [], stats: {}, available_tags: [] };
    const actionUrl = app.dataset.actionUrl;
    const scannerUrl = app.dataset.scannerUrl;

    const refs = {
        startListeningBtn: document.getElementById('startListeningBtn'),
        manualFetchBtn: document.getElementById('manualFetchBtn'),
        testFirebaseBtn: document.getElementById('testFirebaseBtn'),
        saveCapturedTagBtn: document.getElementById('saveCapturedTag'),
        clearCapturedBtn: document.getElementById('clearCapturedBtn'),
        capturedInput: document.getElementById('capturedRfidInput'),
        manualInput: document.getElementById('manualRfidInput'),
        listeningStatus: document.getElementById('listeningStatus'),
        scanMeta: document.getElementById('scanMeta'),
        assignForm: document.getElementById('assignRfidForm'),
        assignTagsSelect: document.getElementById('assignableTagsSelect'),
        studentSearch: document.getElementById('assignStudentSearch'),
        studentSuggestions: document.getElementById('studentSuggestions'),
        selectedStudentDisplay: document.getElementById('selectedStudentDisplay'),
        selectedStudentId: document.getElementById('selectedStudentId'),
        clearStudentSelection: document.getElementById('clearStudentSelection'),
        tagsTableBody: document.getElementById('rfidTagsTableBody'),
        refreshBtn: document.getElementById('refreshTagsBtn'),
        alertContainer: document.getElementById('rfidAlert'),
        statTotal: document.getElementById('statTotalTags'),
        statAssigned: document.getElementById('statAssigned'),
        statAvailable: document.getElementById('statAvailable'),
        statDisabled: document.getElementById('statDisabled')
    };

    let listening = false;
    let pollTimer = null;
    let lastScanSignature = null;
    let lastTimestamp = null;
    let lastUid = null;
    let lastScanId = null;
    let selectedStudent = null;
    let suggestionTimer = null;

    function updateListeningIndicator(status, message) {
        if (!refs.listeningStatus) return;
        const dot = refs.listeningStatus.querySelector('.status-dot');
        refs.listeningStatus.textContent = '';
        const spanDot = dot || document.createElement('span');
        spanDot.className = 'status-dot rounded-circle me-2';
        spanDot.style.width = '8px';
        spanDot.style.height = '8px';

        const text = document.createElement('span');
        text.textContent = message;

        switch (status) {
            case 'listening':
                spanDot.classList.add('bg-success');
                spanDot.classList.remove('bg-secondary', 'bg-warning');
                break;
            case 'polling':
                spanDot.classList.add('bg-warning');
                spanDot.classList.remove('bg-secondary', 'bg-success');
                break;
            default:
                spanDot.classList.add('bg-secondary');
                spanDot.classList.remove('bg-success', 'bg-warning');
        }

        refs.listeningStatus.appendChild(spanDot);
        refs.listeningStatus.appendChild(text);
    }

    function toggleListening() {
        listening = !listening;
        if (listening) {
            updateListeningIndicator('listening', 'Listening...');
            refs.startListeningBtn.innerHTML = '<i class="bi bi-stop-circle me-1"></i>Stop Listening';
            refs.startListeningBtn.classList.remove('btn-success');
            refs.startListeningBtn.classList.add('btn-danger');
            // Reset all tracking variables
            lastScanSignature = null;
            lastTimestamp = null;
            lastUid = null;
            lastScanId = null;
            // Immediate fetch
            fetchLatestScan();
            // Poll every 1 second for faster detection
            pollTimer = setInterval(fetchLatestScan, 1000);
            showAlert('info', 'Nakikinig na sa Firebase. I-tap ang RFID card sa Arduino scanner.', 3000);
        } else {
            updateListeningIndicator('idle', 'Idle');
            refs.startListeningBtn.innerHTML = '<i class="bi bi-broadcast-pin me-1"></i>Start Listening';
            refs.startListeningBtn.classList.add('btn-success');
            refs.startListeningBtn.classList.remove('btn-danger');
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            showAlert('info', 'Natigil ang listening.', 2000);
        }
    }

    function fetchLatestScan(manualTrigger = false) {
        if (!scannerUrl) {
            showAlert('danger', 'Firebase scanner URL is missing.');
            return;
        }
        updateListeningIndicator('polling', manualTrigger ? 'Manual fetch...' : 'Listening...');
        
        // Add timestamp to prevent caching
        const url = `${scannerUrl}?t=${Date.now()}`;
        console.log('Fetching from Firebase:', url);
        
        fetch(url)
            .then(res => {
                console.log('Firebase response status:', res.status);
                if (!res.ok) {
                    if (res.status === 404) {
                        // 404 is OK - means no data yet
                        return null;
                    }
                    throw new Error(`HTTP ${res.status}`);
                }
                return res.json();
            })
            .then(payload => {
                console.log('Firebase payload received:', payload);
                handleScanPayload(payload, manualTrigger);
            })
            .catch(err => {
                if (manualTrigger) {
                    showAlert('warning', 'Hindi makakonekta sa Firebase. Siguraduhing naka-setup ang Arduino at naka-write sa tamang path.');
                }
                updateListeningIndicator('listening', 'Waiting for scan...');
                console.error('Firebase fetch error:', err);
            });
    }

    function handleScanPayload(payload, manualTrigger) {
        console.log('Handling payload:', payload, 'Type:', typeof payload);
        
        // Handle null or empty response
        if (!payload || payload === null || payload === 'null') {
            if (manualTrigger) {
                showAlert('info', 'Walang data sa Firebase. I-tap ang RFID card sa Arduino scanner.');
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }
        
        // Handle empty object
        if (typeof payload === 'object' && Object.keys(payload).length === 0) {
            if (manualTrigger) {
                showAlert('info', 'Walang data sa Firebase. I-tap ang RFID card sa Arduino scanner.');
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }

        // Handle string payload (direct UID)
        if (typeof payload === 'string' && payload.trim().length > 0) {
            const uid = payload.trim().replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            if (uid && uid !== lastUid) {
                lastUid = uid;
                lastTimestamp = Date.now();
                lastScanSignature = uid;
                refs.capturedInput.value = uid;
                refs.scanMeta.textContent = 'Nakakuha ng UID mula sa Firebase';
                updateListeningIndicator('listening', 'Ready to register');
                if (typeof showToast === 'function') {
                    showToast('Nakakuha ng RFID: ' + uid, 'success');
                }
                console.log('✓ String UID detected:', uid);
            }
            return;
        }

        // Handle object payload
        if (typeof payload !== 'object') {
            if (manualTrigger) {
                showAlert('warning', 'Invalid payload format from Firebase.');
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }

        const uid = extractUid(payload);
        const ts = extractTimestamp(payload);
        const currentTimestamp = extractTimestampValue(payload);
        const scanId = payload.scan_id || payload.scanId || null;
        
        console.log('Extracted UID:', uid, 'Scan ID:', scanId, 'Timestamp:', currentTimestamp, 'Last Scan ID:', lastScanId);

        if (!uid) {
            if (manualTrigger) {
                showAlert('warning', 'Nakakuha ng data pero walang UID. Siguraduhing tama ang format ng Arduino payload.');
                console.log('Received payload:', payload);
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }

        // Check if this is a new scan
        // Priority: Use scan_id if available (most reliable), otherwise use timestamp + UID
        let isNewScan = false;
        if (scanId !== null && scanId !== undefined) {
            // Use scan_id for detection (most reliable)
            isNewScan = (scanId !== lastScanId);
        } else {
            // Fallback: Use UID + timestamp
            isNewScan = (uid !== lastUid) || (currentTimestamp && currentTimestamp > (lastTimestamp || 0));
        }
        
        if (!isNewScan && !manualTrigger) {
            // Same scan, don't process
            updateListeningIndicator('listening', 'Waiting for new scan...');
            return;
        }

        // This is a new scan - update tracking
        lastUid = uid;
        lastTimestamp = currentTimestamp || Date.now();
        lastScanId = scanId;
        lastScanSignature = scanId ? `${uid}-${scanId}` : `${uid}-${lastTimestamp}`;
        
        // Update UI
        refs.capturedInput.value = uid;
        refs.scanMeta.textContent = ts ? `Huling scan: ${ts}` : 'Nakakuha ng scan mula sa Firebase.';
        updateListeningIndicator('listening', 'Ready to register');
        
        // Show notification
        if (typeof showToast === 'function') {
            showToast('Nakakuha ng RFID: ' + uid, 'success');
        }
        
        console.log('✓ New RFID scan detected:', uid, 'Scan ID:', scanId);
    }

    function extractUid(payload) {
        if (!payload) return '';
        
        // Try multiple common field names that Arduino might use
        const candidates = [
            payload.uid,
            payload.UID,
            payload.Uid,
            payload.card,
            payload.CARD,
            payload.tag,
            payload.TAG,
            payload.value,
            payload.VALUE,
            payload.rfid,
            payload.RFID,
            payload.id,
            payload.ID,
            payload.data,
            payload.cardId,
            payload.card_id,
            payload.tagId,
            payload.tag_id
        ];
        
        // Find first non-empty value
        let found = candidates.find(val => {
            if (val === null || val === undefined) return false;
            const str = String(val).trim();
            return str.length > 0;
        });
        
        if (!found) {
            // Try to extract from nested objects
            if (payload.data && typeof payload.data === 'object') {
                found = payload.data.uid || payload.data.card || payload.data.tag || payload.data.value;
            }
        }
        
        if (!found) return '';
        
        // Clean and format UID (remove non-alphanumeric, convert to uppercase)
        const cleaned = String(found).replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        return cleaned;
    }

    function extractTimestamp(payload) {
        if (!payload) return null;
        
        // Try various timestamp field names
        if (payload.scanned_at) return payload.scanned_at;
        if (payload.scannedAt) return payload.scannedAt;
        if (payload.timestamp) {
            // Handle both string and number timestamps
            if (typeof payload.timestamp === 'number') {
                const date = new Date(payload.timestamp * 1000);
                return date.toLocaleString();
            }
            return payload.timestamp;
        }
        if (payload.time) {
            if (typeof payload.time === 'number') {
                const date = new Date(payload.time * 1000);
                return date.toLocaleString();
            }
            return payload.time;
        }
        if (payload.server_time) {
            const date = new Date(payload.server_time * 1000);
            return date.toLocaleString();
        }
        if (payload.created_at) return payload.created_at;
        if (payload.createdAt) return payload.createdAt;
        
        return new Date().toLocaleString(); // Fallback to current time
    }
    
    function extractTimestampValue(payload) {
        if (!payload) return null;
        
        // Extract numeric timestamp for comparison
        if (typeof payload.timestamp === 'number') return payload.timestamp;
        if (typeof payload.server_time === 'number') return payload.server_time;
        if (typeof payload.time === 'number') return payload.time;
        
        // Try to parse string timestamps
        if (payload.scanned_at && typeof payload.scanned_at === 'string') {
            const parsed = Date.parse(payload.scanned_at);
            if (!isNaN(parsed)) return Math.floor(parsed / 1000);
        }
        
        // Fallback: use current time
        return Math.floor(Date.now() / 1000);
    }

    function registerTag() {
        const activeUid = refs.capturedInput.value.trim() || refs.manualInput.value.trim();
        if (!activeUid) {
            showAlert('warning', 'Scan an RFID card or type the UID first.');
            return;
        }

        refs.saveCapturedTagBtn.disabled = true;
        postAction('register_tag', {
            uid: activeUid,
            source: listening ? 'live_scanner' : 'manual_input'
        }).then(response => {
            refs.saveCapturedTagBtn.disabled = false;
            if (!response.success) {
                showAlert('danger', response.message || 'Failed to register tag.');
                return;
            }
            showAlert('success', response.message || 'RFID saved.');
            applyState(response);
            refs.capturedInput.value = '';
            refs.manualInput.value = '';
            refs.scanMeta.textContent = 'Waiting for scan...';
        }).catch(() => {
            refs.saveCapturedTagBtn.disabled = false;
            showAlert('danger', 'Unable to register RFID tag.');
        });
    }

    function assignTag(event) {
        event.preventDefault();
        const tagId = refs.assignTagsSelect.value;
        if (!tagId) {
            showAlert('warning', 'Select an available RFID tag first.');
            return;
        }
        if (!selectedStudent) {
            showAlert('warning', 'Select a student to assign.');
            return;
        }

        refs.assignForm.classList.add('opacity-75');
        postAction('assign_tag', {
            tag_id: tagId,
            student_id: selectedStudent.id
        }).then(response => {
            refs.assignForm.classList.remove('opacity-75');
            if (!response.success) {
                showAlert('danger', response.message || 'Assignment failed.');
                return;
            }
            showAlert('success', response.message || 'Assigned successfully.');
            applyState(response);
            setSelectedStudent(null);
            refs.assignTagsSelect.value = '';
        }).catch(() => {
            refs.assignForm.classList.remove('opacity-75');
            showAlert('danger', 'Unable to assign RFID.');
        });
    }

    function unassignTag(tagId) {
        postAction('unassign_tag', { tag_id: tagId }).then(response => {
            if (!response.success) {
                showAlert('danger', response.message || 'Failed to unassign.');
                return;
            }
            showAlert('success', response.message || 'Tag unassigned.');
            applyState(response);
        }).catch(() => showAlert('danger', 'Unable to unassign tag.'));
    }

    function refreshState(showMessage = true) {
        fetch(`${actionUrl}?action=list_tags`, { credentials: 'same-origin' })
            .then(res => res.json())
            .then(response => {
                if (!response.success) {
                    showAlert('danger', response.message || 'Failed to refresh.');
                    return;
                }
                applyState(response);
                if (showMessage) {
                    showAlert('success', 'RFID data refreshed.');
                }
            })
            .catch(() => showAlert('danger', 'Unable to refresh data.'));
    }

    function applyState(data) {
        if (data.tags) state.tags = data.tags;
        if (data.stats) state.stats = data.stats;
        if (data.available_tags) state.available_tags = data.available_tags;
        renderTagsTable();
        renderStats();
        renderTagOptions();
    }

    function renderTagsTable() {
        if (!refs.tagsTableBody) return;
        if (!state.tags.length) {
            refs.tagsTableBody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4">No RFID tags registered yet.</td></tr>`;
            return;
        }

        const rows = state.tags.map(tag => {
            const studentHtml = tag.student_id
                ? `<div class="fw-semibold">${escapeHtml(tag.student_name || 'Student')}</div>
                   <div class="small text-muted">${escapeHtml(tag.student_student_id || '')}</div>`
                : `<span class="badge bg-light text-secondary">Unassigned</span>`;

            const statusBadge = (() => {
                if (tag.status === 'assigned') return '<span class="badge bg-success">Assigned</span>';
                if (tag.status === 'disabled') return '<span class="badge bg-danger">Disabled</span>';
                return '<span class="badge bg-secondary">Available</span>';
            })();

            const actions = tag.student_id
                ? `<button class="btn btn-sm btn-outline-danger" data-action="unassign" data-tag-id="${tag.id}">
                        <i class="bi bi-x-circle me-1"></i>Unassign
                   </button>`
                : '<span class="text-muted">&mdash;</span>';

            return `
                <tr data-tag-id="${tag.id}">
                    <td>${tag.id}</td>
                    <td><span class="fw-semibold">${escapeHtml(tag.tag_uid)}</span></td>
                    <td>${tag.label ? escapeHtml(tag.label) : '&mdash;'}</td>
                    <td>${studentHtml}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <div>${tag.last_seen ? escapeHtml(tag.last_seen) : '&mdash;'}</div>
                        <div class="small text-muted">${tag.last_source ? escapeHtml(tag.last_source) : ''}</div>
                    </td>
                    <td>${escapeHtml(tag.created_at || '')}</td>
                    <td class="text-end">${actions}</td>
                </tr>
            `;
        }).join('');

        refs.tagsTableBody.innerHTML = rows;
    }

    function renderStats() {
        if (refs.statTotal) refs.statTotal.textContent = state.stats.total ?? 0;
        if (refs.statAssigned) refs.statAssigned.textContent = state.stats.assigned ?? 0;
        if (refs.statAvailable) refs.statAvailable.textContent = state.stats.available ?? 0;
        if (refs.statDisabled) refs.statDisabled.textContent = state.stats.disabled ?? 0;
    }

    function renderTagOptions() {
        if (!refs.assignTagsSelect) return;
        const options = ['<option value="">Select registered RFID</option>'];
        state.available_tags.forEach(tag => {
            options.push(`<option value="${tag.id}">${escapeHtml(tag.tag_uid)}${tag.label ? ' (' + escapeHtml(tag.label) + ')' : ''}</option>`);
        });
        refs.assignTagsSelect.innerHTML = options.join('');
    }

    function setSelectedStudent(student) {
        selectedStudent = student;
        if (!refs.selectedStudentDisplay) return;
        if (student) {
            refs.selectedStudentDisplay.innerHTML = `
                <div>
                    <strong>${escapeHtml(student.name)}</strong>
                    <div class="small text-muted">${escapeHtml(student.student_id)} · ${escapeHtml(student.course || '')} Y${escapeHtml(student.year_level || '')}</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearStudentSelectionDynamic">Clear</button>
            `;
            refs.selectedStudentId.value = student.id;
        } else {
            refs.selectedStudentDisplay.innerHTML = `
                <div>
                    <strong>No student selected.</strong>
                    <div class="small text-muted">Start typing above to search.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearStudentSelectionDynamic">Clear</button>
            `;
            refs.selectedStudentId.value = '';
        }

        const clearBtn = document.getElementById('clearStudentSelectionDynamic');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                refs.studentSearch.value = '';
                setSelectedStudent(null);
            });
        }
    }

    function handleStudentSearchInput() {
        const query = refs.studentSearch.value.trim();
        if (suggestionTimer) {
            clearTimeout(suggestionTimer);
            suggestionTimer = null;
        }
        if (query.length < 2) {
            hideSuggestions();
            return;
        }
        suggestionTimer = setTimeout(() => fetchStudentSuggestions(query), 350);
    }

    function fetchStudentSuggestions(query) {
        fetch(`${actionUrl}?action=search_students&query=${encodeURIComponent(query)}`, { credentials: 'same-origin' })
            .then(res => res.json())
            .then(response => {
                if (!response.success) {
                    hideSuggestions();
                    return;
                }
                renderStudentSuggestions(response.students || []);
            })
            .catch(hideSuggestions);
    }

    function renderStudentSuggestions(list) {
        if (!refs.studentSuggestions) return;
        if (!list.length) {
            refs.studentSuggestions.innerHTML = `<div class="p-2 text-muted">No results found.</div>`;
        } else {
            refs.studentSuggestions.innerHTML = list.map(student => `
                <button type="button" data-student='${JSON.stringify(student)}'>
                    <div class="fw-semibold">${escapeHtml(student.name)}</div>
                    <div class="small text-muted">${escapeHtml(student.student_id)} · ${escapeHtml(student.course || '')} Y${escapeHtml(student.year_level || '')}</div>
                </button>
            `).join('');
        }
        refs.studentSuggestions.classList.remove('d-none');
    }

    function hideSuggestions() {
        if (!refs.studentSuggestions) return;
        refs.studentSuggestions.classList.add('d-none');
        refs.studentSuggestions.innerHTML = '';
    }

    function showAlert(type, message, timeout = 5000) {
        if (!refs.alertContainer) return;
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.textContent = message;
        refs.alertContainer.innerHTML = '';
        refs.alertContainer.appendChild(alert);
        if (timeout) {
            setTimeout(() => alert.remove(), timeout);
        }
    }

    function postAction(action, payload) {
        const body = new URLSearchParams();
        body.append('action', action);
        Object.keys(payload).forEach(key => body.append(key, payload[key]));

        return fetch(actionUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body,
            credentials: 'same-origin'
        }).then(res => res.json());
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Event listeners
    if (refs.startListeningBtn) {
        refs.startListeningBtn.addEventListener('click', toggleListening);
    }
    if (refs.manualFetchBtn) {
        refs.manualFetchBtn.addEventListener('click', () => fetchLatestScan(true));
    }
    if (refs.saveCapturedTagBtn) {
        refs.saveCapturedTagBtn.addEventListener('click', registerTag);
    }
    if (refs.clearCapturedBtn) {
        refs.clearCapturedBtn.addEventListener('click', () => {
            refs.capturedInput.value = '';
            refs.manualInput.value = '';
            refs.scanMeta.textContent = 'Maghintay ng RFID scan...';
            // Reset all tracking to allow same card to be scanned again
            lastScanSignature = null;
            lastTimestamp = null;
            lastUid = null;
            lastScanId = null;
        });
    }
    if (refs.manualInput) {
        refs.manualInput.addEventListener('input', () => {
            refs.manualInput.value = refs.manualInput.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        });
    }
    if (refs.assignForm) {
        refs.assignForm.addEventListener('submit', assignTag);
    }
    if (refs.studentSearch) {
        refs.studentSearch.addEventListener('input', handleStudentSearchInput);
        refs.studentSearch.addEventListener('focus', handleStudentSearchInput);
    }
    if (refs.studentSuggestions) {
        refs.studentSuggestions.addEventListener('click', event => {
            const button = event.target.closest('button[data-student]');
            if (!button) return;
            const data = JSON.parse(button.getAttribute('data-student'));
            setSelectedStudent(data);
            refs.studentSearch.value = data.name;
            hideSuggestions();
        });
    }
    document.addEventListener('click', event => {
        if (!refs.studentSuggestions) return;
        if (refs.studentSuggestions.contains(event.target) || refs.studentSearch.contains(event.target)) {
            return;
        }
        hideSuggestions();
    });
    if (refs.tagsTableBody) {
        refs.tagsTableBody.addEventListener('click', event => {
            const btn = event.target.closest('button[data-action="unassign"]');
            if (!btn) return;
            const tagId = btn.getAttribute('data-tag-id');
            if (tagId) {
                unassignTag(tagId);
            }
        });
    }
    if (refs.refreshBtn) {
        refs.refreshBtn.addEventListener('click', () => refreshState(true));
    }
    
    // Test Firebase connection
    if (refs.testFirebaseBtn) {
        refs.testFirebaseBtn.addEventListener('click', () => {
            console.log('Testing Firebase connection...');
            console.log('Scanner URL:', scannerUrl);
            refs.testFirebaseBtn.disabled = true;
            refs.testFirebaseBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            
            fetch(`${scannerUrl}?t=${Date.now()}`)
                .then(res => {
                    console.log('Test response status:', res.status);
                    console.log('Test response headers:', res.headers);
                    if (res.status === 404) {
                        showAlert('info', 'Firebase connection OK pero walang data pa. I-tap ang RFID card sa Arduino.', 5000);
                        return null;
                    }
                    if (!res.ok) {
                        throw new Error(`HTTP ${res.status}`);
                    }
                    return res.json();
                })
                .then(data => {
                    console.log('Test data received:', data);
                    if (data) {
                        showAlert('success', 'Firebase connection OK! Nakakuha ng data: ' + JSON.stringify(data), 5000);
                        handleScanPayload(data, true);
                    } else {
                        showAlert('info', 'Firebase connection OK pero walang data pa.', 5000);
                    }
                })
                .catch(err => {
                    console.error('Test error:', err);
                    showAlert('danger', 'Firebase connection failed: ' + err.message + '. Check console for details.', 8000);
                })
                .finally(() => {
                    refs.testFirebaseBtn.disabled = false;
                    refs.testFirebaseBtn.innerHTML = '<i class="bi bi-wifi"></i>';
                });
        });
    }

    // Initial render
    applyState(state);
    setSelectedStudent(null);
});


