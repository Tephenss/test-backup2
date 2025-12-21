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

    // Sound effect functions using Web Audio API
    let audioContext = null;
    let masterGainNode = null;
    
    function initAudioContext() {
        if (!audioContext) {
            try {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                
                // Create a master gain node with maximum amplification
                // This ensures sound plays at maximum volume regardless of system settings
                masterGainNode = audioContext.createGain();
                masterGainNode.gain.value = 1.0; // Maximum gain
                masterGainNode.connect(audioContext.destination);
            } catch (e) {
                console.log('Web Audio API not supported:', e);
            }
        }
        return audioContext;
    }

    function playSuccessSound() {
        const ctx = initAudioContext();
        if (!ctx || !masterGainNode) return;
        
        try {
            // Create a pleasant success beep (two-tone ascending)
            const oscillator1 = ctx.createOscillator();
            const oscillator2 = ctx.createOscillator();
            const gainNode = ctx.createGain();
            
            oscillator1.type = 'sine';
            oscillator1.frequency.setValueAtTime(523.25, ctx.currentTime); // C5
            oscillator2.type = 'sine';
            oscillator2.frequency.setValueAtTime(659.25, ctx.currentTime); // E5
            
            // Set volume to maximum (1.0 = 100%) and amplify further
            // Using 1.0 ensures maximum volume within audio context
            gainNode.gain.setValueAtTime(1.0, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.2);
            
            // Connect through master gain node for maximum amplification
            oscillator1.connect(gainNode);
            oscillator2.connect(gainNode);
            gainNode.connect(masterGainNode);
            
            oscillator1.start(ctx.currentTime);
            oscillator2.start(ctx.currentTime);
            oscillator1.stop(ctx.currentTime + 0.2);
            oscillator2.stop(ctx.currentTime + 0.2);
        } catch (e) {
            console.log('Could not play success sound:', e);
        }
    }

    function playErrorSound() {
        const ctx = initAudioContext();
        if (!ctx || !masterGainNode) return;
        
        try {
            // Create an error beep (low descending tone)
            const oscillator = ctx.createOscillator();
            const gainNode = ctx.createGain();
            
            oscillator.type = 'sawtooth';
            oscillator.frequency.setValueAtTime(200, ctx.currentTime);
            oscillator.frequency.exponentialRampToValueAtTime(150, ctx.currentTime + 0.3);
            
            // Set volume to maximum (1.0 = 100%) and amplify further
            // Using 1.0 ensures maximum volume within audio context
            gainNode.gain.setValueAtTime(1.0, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            
            // Connect through master gain node for maximum amplification
            oscillator.connect(gainNode);
            gainNode.connect(masterGainNode);
            
            oscillator.start(ctx.currentTime);
            oscillator.stop(ctx.currentTime + 0.3);
        } catch (e) {
            console.log('Could not play error sound:', e);
        }
    }

    let listening = false;
    let pollTimer = null;
    let lastScanSignature = null;
    let lastTimestamp = null;
    let lastUid = null;
    let lastScanId = null;
    let selectedStudent = null;
    let suggestionTimer = null;
    let listenerStartTime = null; // Track when listener started to ignore old scans

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
        if (!scannerUrl) {
            console.error('Scanner URL is missing!');
            showAlert('danger', 'Firebase scanner URL is not configured. Please check your Firebase setup.');
            return;
        }
        
        listening = !listening;
        if (listening) {
            console.log('Starting listener...');
            updateListeningIndicator('listening', 'Listening...');
            refs.startListeningBtn.innerHTML = '<i class="bi bi-stop-circle me-1"></i>Stop Listening';
            refs.startListeningBtn.classList.remove('btn-success');
            refs.startListeningBtn.classList.add('btn-danger');
            // Reset all tracking variables
            lastScanSignature = null;
            lastTimestamp = null;
            lastUid = null;
            lastScanId = null;
            // Track when listener started - ignore scans before this time
            listenerStartTime = Date.now();
            // Clear the captured input field
            if (refs.capturedInput) refs.capturedInput.value = '';
            if (refs.scanMeta) refs.scanMeta.textContent = 'Waiting for RFID scan...';
            // Start polling immediately but mark initial data as baseline
            // Poll every 1 second for faster detection
            pollTimer = setInterval(() => {
                fetchLatestScan(false);
            }, 1000);
            // First fetch after 1 second to establish baseline
            setTimeout(() => {
                if (listening) {
                    fetchLatestScan(false);
                }
            }, 1000);
            showAlert('info', 'Now listening to Firebase. Tap the RFID card on the Arduino scanner.', 3000);
        } else {
            updateListeningIndicator('idle', 'Idle');
            refs.startListeningBtn.innerHTML = '<i class="bi bi-broadcast-pin me-1"></i>Start Listening';
            refs.startListeningBtn.classList.add('btn-success');
            refs.startListeningBtn.classList.remove('btn-danger');
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            listenerStartTime = null;
            // Clear the captured input when stopping
            refs.capturedInput.value = '';
            refs.scanMeta.textContent = 'Waiting for RFID scan...';
            showAlert('info', 'Listening stopped.', 2000);
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
                    showAlert('warning', 'Cannot connect to Firebase. Make sure the Arduino is set up and writing to the correct path.');
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
                showAlert('info', 'No data in Firebase. Tap the RFID card on the Arduino scanner.');
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }
        
        // Handle empty object
        if (typeof payload === 'object' && Object.keys(payload).length === 0) {
            if (manualTrigger) {
                showAlert('info', 'No data in Firebase. Tap the RFID card on the Arduino scanner.');
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }

        // Handle string payload (direct UID)
        if (typeof payload === 'string' && payload.trim().length > 0) {
            const uid = payload.trim().replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            
            // Check if listener has started (unless manual trigger)
            if (!manualTrigger && !listenerStartTime) {
                console.log('Ignoring string scan - listener not started yet');
                updateListeningIndicator('listening', 'Waiting for scan...');
                return;
            }
            
            // For string payloads without timestamp, we need to be more careful
            // Only process if this is a different UID OR if enough time has passed since last scan
            const timeSinceLastScan = lastTimestamp ? (Date.now() - lastTimestamp) : Infinity;
            const timeSinceListenerStart = listenerStartTime ? (Date.now() - listenerStartTime) : 0;
            const isNewScan = (uid !== lastUid) || (timeSinceLastScan > 5000); // Allow same UID if 5+ seconds passed
            
            // Additional check: if this is the first scan after starting listener, wait a bit
            // to avoid capturing old Firebase data (but not too strict)
            if (!manualTrigger && listenerStartTime && !lastUid) {
                // First scan after starting - only process if listener has been running for at least 2 seconds
                // This gives time for old data to be skipped
                if (timeSinceListenerStart < 2000) {
                    console.log('Ignoring first string scan - listener just started (', timeSinceListenerStart, 'ms ago)');
                    updateListeningIndicator('listening', 'Waiting for new scan...');
                    return;
                } else {
                    console.log('First string scan after listener started (', timeSinceListenerStart, 'ms ago):', uid);
                }
            }
            
            if (isNewScan && uid) {
                lastUid = uid;
                lastTimestamp = Date.now();
                lastScanSignature = uid;
                lastScanId = null; // Reset scan_id for string payloads
                
                if (refs.capturedInput) {
                    refs.capturedInput.value = uid;
                }
                if (refs.scanMeta) {
                    refs.scanMeta.textContent = 'UID captured from Firebase';
                }
                updateListeningIndicator('listening', 'Checking tag...');
                
                // Check if tag exists
                checkTagExists(uid).then(tagExists => {
                    if (tagExists.exists) {
                        updateListeningIndicator('listening', 'Tag already registered');
                        showAlert('danger', tagExists.message || 'This RFID tag is already registered in the system.', 5000);
                        playErrorSound(); // Play error sound
                        if (refs.saveCapturedTagBtn) {
                            refs.saveCapturedTagBtn.disabled = true;
                            refs.saveCapturedTagBtn.title = 'This tag is already registered';
                        }
                        if (refs.scanMeta) {
                            refs.scanMeta.textContent = 'Tag already exists in system.';
                        }
                    } else {
                        updateListeningIndicator('listening', 'Ready to register');
                        showAlert('success', 'RFID captured: ' + uid, 3000);
                        playSuccessSound(); // Play success sound
                        if (refs.saveCapturedTagBtn) {
                            refs.saveCapturedTagBtn.disabled = false;
                            refs.saveCapturedTagBtn.title = '';
                        }
                        if (typeof showToast === 'function') {
                            showToast('RFID captured: ' + uid, 'success');
                        }
                    }
                }).catch(error => {
                    console.error('Error checking tag:', error);
                    updateListeningIndicator('listening', 'Ready to register');
                    if (refs.saveCapturedTagBtn) {
                        refs.saveCapturedTagBtn.disabled = false;
                    }
                });
                
                console.log('✓ String UID detected:', uid);
            } else if (uid && uid === lastUid) {
            // Same UID, check if enough time has passed
            if (timeSinceLastScan > 5000) {
                // Enough time passed, treat as new scan
                lastUid = uid;
                lastTimestamp = Date.now();
                if (refs.capturedInput) refs.capturedInput.value = uid;
                if (refs.scanMeta) refs.scanMeta.textContent = 'UID captured from Firebase (re-scan)';
                updateListeningIndicator('listening', 'Checking tag...');
                
                // Check if tag exists
                checkTagExists(uid).then(tagExists => {
                    if (tagExists.exists) {
                        updateListeningIndicator('listening', 'Tag already registered');
                        showAlert('danger', tagExists.message || 'This RFID tag is already registered in the system.', 5000);
                        playErrorSound(); // Play error sound
                        if (refs.saveCapturedTagBtn) {
                            refs.saveCapturedTagBtn.disabled = true;
                            refs.saveCapturedTagBtn.title = 'This tag is already registered';
                        }
                    } else {
                        updateListeningIndicator('listening', 'Ready to register');
                        showAlert('success', 'RFID captured: ' + uid, 3000);
                        playSuccessSound(); // Play success sound
                        if (refs.saveCapturedTagBtn) {
                            refs.saveCapturedTagBtn.disabled = false;
                            refs.saveCapturedTagBtn.title = '';
                        }
                    }
                });
                console.log('✓ Same UID re-scanned after', timeSinceLastScan, 'ms');
            } else {
                // Not enough time, ignore
                updateListeningIndicator('listening', 'Waiting for new scan...');
                console.log('Ignoring duplicate string scan (same UID, only', timeSinceLastScan, 'ms ago)');
            }
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
        
        console.log('Extracted UID:', uid, 'Scan ID:', scanId, 'Timestamp:', currentTimestamp, 'Last Scan ID:', lastScanId, 'Listener Start:', listenerStartTime);

        if (!uid) {
            if (manualTrigger) {
                showAlert('warning', 'Received data but no UID found. Make sure the Arduino payload format is correct.');
                console.log('Received payload:', payload);
            }
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }

        // Check if listener has started (unless manual trigger)
        if (!manualTrigger && !listenerStartTime) {
            console.log('Ignoring scan - listener not started yet');
            updateListeningIndicator('listening', 'Waiting for scan...');
            return;
        }

        // CRITICAL: Ignore scans that happened before the listener started
        // This prevents capturing old Firebase data when listener first starts
        // But be more lenient - only ignore if scan is clearly old (more than 5 seconds before start)
        if (!manualTrigger && listenerStartTime && currentTimestamp) {
            // Convert to milliseconds if needed
            const scanTimeMs = currentTimestamp > 1000000000000 ? currentTimestamp : currentTimestamp * 1000;
            // Only ignore if scan is clearly old (more than 5 seconds before listener started)
            // This gives a buffer for clock differences while still catching new scans
            if (scanTimeMs < (listenerStartTime - 5000)) {
                console.log('Ignoring old scan (before listener started):', uid, 
                    'Scan time:', new Date(scanTimeMs), 
                    'Listener started:', new Date(listenerStartTime),
                    'Difference:', (listenerStartTime - scanTimeMs), 'ms');
                updateListeningIndicator('listening', 'Waiting for new scan...');
                return;
            } else {
                console.log('Scan timestamp is recent enough:', uid, 
                    'Scan time:', new Date(scanTimeMs), 
                    'Listener started:', new Date(listenerStartTime),
                    'Difference:', (listenerStartTime - scanTimeMs), 'ms');
            }
        }

        // Determine if this is a new scan
        let isNewScan = false;
        
        // Priority 1: Use scan_id if available (most reliable)
        if (scanId !== null && scanId !== undefined && scanId !== '') {
            isNewScan = (scanId !== lastScanId);
            console.log('Using scan_id for detection:', scanId, 'Last:', lastScanId, 'IsNew:', isNewScan);
        } 
        // Priority 2: Use UID comparison - if UID is different, it's definitely new
        else if (uid !== lastUid) {
            isNewScan = true;
            console.log('New UID detected:', uid, 'Last:', lastUid);
        }
        // Priority 3: Same UID but check timestamp - allow if timestamp is newer (within reasonable range)
        else if (currentTimestamp) {
            // Convert to milliseconds if needed (Firebase timestamps are usually in seconds)
            const scanTimeMs = currentTimestamp > 1000000000000 ? currentTimestamp : currentTimestamp * 1000;
            const lastTimeMs = lastTimestamp > 1000000000000 ? lastTimestamp : (lastTimestamp || 0) * 1000;
            
            // If timestamp is significantly newer (more than 2 seconds), treat as new scan
            // This handles cases where the same card is tapped again
            if (scanTimeMs > lastTimeMs + 2000) {
                isNewScan = true;
                console.log('Same UID but newer timestamp:', new Date(scanTimeMs), 'Last:', new Date(lastTimeMs));
            } else {
                console.log('Same UID and similar timestamp - ignoring duplicate');
            }
        }
        // Priority 4: If no timestamp and same UID, check if enough time has passed (5 seconds)
        else if (lastTimestamp) {
            const timeSinceLastScan = Date.now() - (lastTimestamp > 1000000000000 ? lastTimestamp : lastTimestamp * 1000);
            if (timeSinceLastScan > 5000) {
                isNewScan = true;
                console.log('Same UID but enough time passed:', timeSinceLastScan, 'ms');
            } else {
                console.log('Same UID, not enough time passed:', timeSinceLastScan, 'ms');
            }
        }
        // Priority 5: If we have no previous scan, this is new (but verify timestamp if available)
        else if (!lastUid) {
            // If we have a timestamp, verify it's recent
            if (currentTimestamp && listenerStartTime) {
                const scanTimeMs = currentTimestamp > 1000000000000 ? currentTimestamp : currentTimestamp * 1000;
                if (scanTimeMs >= (listenerStartTime - 5000)) {
                    isNewScan = true;
                    console.log('No previous scan - treating as new (verified timestamp)');
                } else {
                    console.log('No previous scan but timestamp is too old - ignoring');
                }
            } else {
                // No timestamp available - treat as new if listener has been running for a bit
                // This handles cases where Arduino doesn't send timestamps
                if (listenerStartTime && (Date.now() - listenerStartTime) > 2000) {
                    isNewScan = true;
                    console.log('No previous scan and no timestamp - treating as new (listener running for', (Date.now() - listenerStartTime), 'ms)');
                } else {
                    console.log('No previous scan but listener just started - ignoring to avoid old data');
                }
            }
        }
        
        // Only process if it's a new scan (or manual trigger)
        if (!isNewScan && !manualTrigger) {
            updateListeningIndicator('listening', 'Waiting for new scan...');
            return;
        }
        
        // For manual trigger, always process (but still check if it's a duplicate for UI purposes)
        if (manualTrigger && uid === lastUid && scanId === lastScanId) {
            console.log('Manual trigger - same scan as before');
            updateListeningIndicator('listening', 'Same scan as before');
            return;
        }

        // This is a new scan - update tracking
        lastUid = uid;
        // Store timestamp in milliseconds for easier comparison
        if (currentTimestamp) {
            // Convert to milliseconds if it's in seconds (Firebase format)
            lastTimestamp = currentTimestamp > 1000000000000 ? currentTimestamp : currentTimestamp * 1000;
        } else {
            lastTimestamp = Date.now();
        }
        lastScanId = scanId;
        lastScanSignature = scanId ? `${uid}-${scanId}` : `${uid}-${lastTimestamp}`;
        
        // Update UI
        if (refs.capturedInput) {
            refs.capturedInput.value = uid;
        }
        if (refs.scanMeta) {
            refs.scanMeta.textContent = ts ? `Last scan: ${ts}` : 'Scan captured from Firebase.';
        }
        updateListeningIndicator('listening', 'Checking tag...');
        
        // Check if tag already exists in the system
        checkTagExists(uid).then(tagExists => {
            if (tagExists.exists) {
                // Tag already exists - show error immediately
                updateListeningIndicator('listening', 'Tag already registered');
                showAlert('danger', tagExists.message || 'This RFID tag is already registered in the system.', 5000);
                playErrorSound(); // Play error sound
                if (refs.saveCapturedTagBtn) {
                    refs.saveCapturedTagBtn.disabled = true;
                    refs.saveCapturedTagBtn.title = 'This tag is already registered';
                }
                if (refs.scanMeta) {
                    refs.scanMeta.textContent = 'Tag already exists in system.';
                }
                console.log('⚠ Tag already exists:', uid);
            } else {
                // Tag is new - ready to register
                updateListeningIndicator('listening', 'Ready to register');
                showAlert('success', 'RFID captured: ' + uid, 3000);
                playSuccessSound(); // Play success sound
                if (refs.saveCapturedTagBtn) {
                    refs.saveCapturedTagBtn.disabled = false;
                    refs.saveCapturedTagBtn.title = '';
                }
                if (typeof showToast === 'function') {
                    showToast('RFID captured: ' + uid, 'success');
                }
                console.log('✓ New RFID scan detected:', uid, 'Scan ID:', scanId, 'Timestamp:', new Date(lastTimestamp));
            }
        }).catch(error => {
            console.error('Error checking tag existence:', error);
            // On error, still allow registration (will be caught at registration time)
            updateListeningIndicator('listening', 'Ready to register');
            if (refs.saveCapturedTagBtn) {
                refs.saveCapturedTagBtn.disabled = false;
            }
        });
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

    function checkTagExists(uid) {
        return fetch(`${actionUrl}?action=check_tag_exists&uid=${encodeURIComponent(uid)}`, {
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(response => {
            if (!response.success) {
                return { exists: false };
            }
            return response;
        })
        .catch(error => {
            console.error('Error checking tag:', error);
            return { exists: false };
        });
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
                // Show error message for already registered tags or other errors
                showAlert('danger', response.message || 'Failed to register tag.');
                playErrorSound(); // Play error sound
                return;
            }
            
            // Check if tag already exists (legacy support - backend now returns error)
            if (response.existing) {
                showAlert('warning', 'This RFID tag is already registered in the system.');
                playErrorSound(); // Play error sound
                applyState(response);
                return;
            }
            
            // Success - tag registered
            showAlert('success', response.message || 'RFID tag registered successfully.');
            playSuccessSound(); // Play success sound
            applyState(response);
            refs.capturedInput.value = '';
            refs.manualInput.value = '';
            refs.scanMeta.textContent = 'Waiting for scan...';
        }).catch((error) => {
            refs.saveCapturedTagBtn.disabled = false;
            console.error('Registration error:', error);
            showAlert('danger', 'Unable to register RFID tag. Please try again.');
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

    function blockTag(tagId) {
        if (!confirm('Are you sure you want to block this RFID tag? This will unassign the student (if assigned) and remove the tag from the system.')) {
            return;
        }
        
        // Disable the button to prevent multiple clicks
        const btn = document.querySelector(`button[data-action="block"][data-tag-id="${tagId}"]`);
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Blocking...';
        }
        
        postAction('block_tag', { tag_id: tagId }).then(response => {
            if (!response.success) {
                showAlert('danger', response.message || 'Failed to block tag.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-ban me-1"></i>Block';
                }
                return;
            }
            showAlert('success', response.message || 'Tag blocked and removed successfully.');
            // Remove the row from the table immediately
            const row = document.querySelector(`tr[data-tag-id="${tagId}"]`);
            if (row) {
                row.remove();
            }
            // Update state
            applyState(response);
            // Update stats
            renderStats();
        }).catch((err) => {
            console.error('Block tag error:', err);
            showAlert('danger', 'Unable to block tag.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-ban me-1"></i>Block';
            }
        });
    }

    function refreshState(showMessage = true) {
        // Add timestamp to prevent caching
        fetch(`${actionUrl}?action=list_tags&t=${Date.now()}`, { credentials: 'same-origin' })
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

    // Search functionality - declare variables first
    const searchInput = document.getElementById('rfidSearchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    let allTags = []; // Store all tags for filtering
    
    function filterTagsTable(searchQuery) {
        if (!refs.tagsTableBody) return;
        
        const query = searchQuery.toLowerCase().trim();
        let filteredTags = allTags;
        
        if (query.length > 0) {
            filteredTags = allTags.filter(tag => {
                const studentName = (tag.student_name || '').toLowerCase();
                const studentId = (tag.student_student_id || '').toLowerCase();
                const tagUid = (tag.tag_uid || '').toLowerCase();
                
                return studentName.includes(query) || 
                       studentId.includes(query) || 
                       tagUid.includes(query);
            });
        }
        
        renderFilteredTagsTable(filteredTags);
        updateSearchResultCount(filteredTags.length, allTags.length, query.length > 0);
    }
    
    function renderFilteredTagsTable(tags) {
        if (!refs.tagsTableBody) return;
        
        if (tags.length === 0) {
            refs.tagsTableBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No tags found matching your search.</td></tr>`;
            return;
        }
        
        const rows = tags.map(tag => {
            const studentHtml = tag.student_id
                ? `<div class="fw-semibold">${escapeHtml(tag.student_name || 'Student')}</div>
                   <div class="small text-muted">${escapeHtml(tag.student_student_id || '')}</div>`
                : `<span class="badge bg-light text-secondary">Unassigned</span>`;

            const statusBadge = (() => {
                if (tag.status === 'assigned') return '<span class="badge bg-success">Assigned</span>';
                if (tag.status === 'disabled') return '<span class="badge bg-danger">Disabled</span>';
                return '<span class="badge bg-secondary">Available</span>';
            })();

            const actions = `<button class="btn btn-sm btn-outline-danger" data-action="block" data-tag-id="${tag.id}">
                    <i class="bi bi-ban me-1"></i>Block
               </button>`;

            return `
                <tr data-tag-id="${tag.id}">
                    <td>${tag.id}</td>
                    <td><span class="fw-semibold">${escapeHtml(tag.tag_uid)}</span></td>
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
    
    function updateSearchResultCount(filtered, total, isSearching) {
        const resultCount = document.getElementById('searchResultCount');
        if (!resultCount) return;
        
        if (isSearching) {
            resultCount.textContent = `Showing ${filtered} of ${total} tag(s)`;
        } else {
            resultCount.textContent = `Total: ${total} tag(s)`;
        }
    }
    
    function applyState(data) {
        if (data.tags) {
            state.tags = data.tags;
            allTags = data.tags; // Store all tags for filtering
        }
        if (data.stats) state.stats = data.stats;
        if (data.available_tags) state.available_tags = data.available_tags;
        
        // Apply current search filter if any
        const currentSearch = searchInput ? searchInput.value.trim() : '';
        if (currentSearch.length > 0) {
            filterTagsTable(currentSearch);
        } else {
            renderTagsTable();
            updateSearchResultCount(allTags.length, allTags.length, false);
        }
        renderStats();
        renderTagOptions();
    }
    
    function renderTagsTable() {
        renderFilteredTagsTable(allTags.length > 0 ? allTags : state.tags);
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
        refs.startListeningBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            console.log('Start Listening button clicked');
            toggleListening();
        });
        console.log('Start Listening button event listener attached');
    } else {
        console.error('Start Listening button not found!');
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
            refs.scanMeta.textContent = 'Waiting for RFID scan...';
            // Reset all tracking to allow same card to be scanned again
            lastScanSignature = null;
            lastTimestamp = null;
            lastUid = null;
            lastScanId = null;
        });
    }
    if (refs.manualInput) {
        let manualInputTimer = null;
        refs.manualInput.addEventListener('input', () => {
            refs.manualInput.value = refs.manualInput.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            
            // Clear previous timer
            if (manualInputTimer) {
                clearTimeout(manualInputTimer);
            }
            
            // Check if tag exists after user stops typing (500ms delay)
            const uid = refs.manualInput.value.trim();
            if (uid.length > 0) {
                manualInputTimer = setTimeout(() => {
                    updateListeningIndicator('listening', 'Checking tag...');
                    checkTagExists(uid).then(tagExists => {
                        if (tagExists.exists) {
                            updateListeningIndicator('listening', 'Tag already registered');
                            showAlert('danger', tagExists.message || 'This RFID tag is already registered in the system.', 5000);
                            playErrorSound(); // Play error sound
                            if (refs.saveCapturedTagBtn) {
                                refs.saveCapturedTagBtn.disabled = true;
                                refs.saveCapturedTagBtn.title = 'This tag is already registered';
                            }
                        } else {
                            updateListeningIndicator('listening', 'Ready to register');
                            playSuccessSound(); // Play success sound
                            if (refs.saveCapturedTagBtn) {
                                refs.saveCapturedTagBtn.disabled = false;
                                refs.saveCapturedTagBtn.title = '';
                            }
                        }
                    }).catch(error => {
                        console.error('Error checking tag:', error);
                        updateListeningIndicator('listening', 'Ready to register');
                        if (refs.saveCapturedTagBtn) {
                            refs.saveCapturedTagBtn.disabled = false;
                        }
                    });
                }, 500);
            } else {
                // Clear input - enable button
                if (refs.saveCapturedTagBtn) {
                    refs.saveCapturedTagBtn.disabled = false;
                    refs.saveCapturedTagBtn.title = '';
                }
                updateListeningIndicator('listening', 'Waiting for scan...');
            }
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
            const btn = event.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const tagId = btn.getAttribute('data-tag-id');
            if (tagId) {
                if (action === 'block') {
                    blockTag(tagId);
                } else if (action === 'unassign') {
                    unassignTag(tagId);
                }
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
                        showAlert('info', 'Firebase connection OK but no data yet. Tap the RFID card on the Arduino.', 5000);
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
                        showAlert('success', 'Firebase connection OK! Received data: ' + JSON.stringify(data), 5000);
                        handleScanPayload(data, true);
                    } else {
                        showAlert('info', 'Firebase connection OK but no data yet.', 5000);
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

    // Search event listeners
    if (searchInput) {
        let searchTimer = null;
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            if (searchTimer) {
                clearTimeout(searchTimer);
            }
            searchTimer = setTimeout(() => {
                filterTagsTable(query);
            }, 300);
        });
    }
    
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', () => {
            if (searchInput) {
                searchInput.value = '';
                filterTagsTable('');
            }
        });
    }

    // Initial render
    allTags = state.tags || [];
    applyState(state);
    setSelectedStudent(null);
});


