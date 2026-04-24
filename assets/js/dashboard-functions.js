// ========== 7. LOADING SPINNER ==========
function showLoading(message = 'Processing...') {
    let overlay = document.querySelector('.loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.innerHTML = `
            <div style="text-align: center;">
                <div class="loading-spinner"></div>
                <div class="loading-text">${message}</div>
            </div>
        `;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.loading-text').innerText = message;
    }
    overlay.classList.add('active');
}

function hideLoading() {
    const overlay = document.querySelector('.loading-overlay');
    if (overlay) {
        overlay.classList.remove('active');
    }
}

// ========== 8. DELETE WITH PASSWORD ==========
function deleteReservation(reservationId, element) {
    const password = prompt('⚠️ SECURITY VERIFICATION REQUIRED\n\nEnter admin password to delete this reservation:\n(Default: AdminDelete2026)');
    
    if (password === null) {
        return; // User cancelled
    }
    
    showLoading('Verifying credentials...');
    
    fetch('delete_reservation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            reservation_id: reservationId,
            password: password
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            // Remove row from table with animation
            const row = element.closest('tr');
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                row.remove();
                showNotification('✅ Reservation deleted successfully!', 'success');
                // Update counts if needed
                location.reload();
            }, 300);
        } else {
            showNotification('❌ ' + data.error, 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('❌ Error: ' + error.message, 'error');
    });
}

// ========== 9. SHOW/HIDE COLUMNS ==========
function toggleColumnMenu() {
    const menu = document.getElementById('columnToggleMenu');
    menu.classList.toggle('show');
}

function toggleColumn(columnName, checkbox) {
    const cells = document.querySelectorAll(`td.${columnName}, th.${columnName}`);
    cells.forEach(cell => {
        cell.style.display = checkbox.checked ? '' : 'none';
    });
    
    // Save preference to localStorage
    localStorage.setItem(`column_${columnName}`, checkbox.checked);
}

function loadColumnPreferences() {
    const columns = ['phone', 'table', 'amount_due', 'created', 'guest_details'];
    columns.forEach(col => {
        const saved = localStorage.getItem(`column_${col}`);
        const checkbox = document.getElementById(`toggle_${col}`);
        if (checkbox && saved !== null) {
            const isVisible = saved === 'true';
            checkbox.checked = isVisible;
            const cells = document.querySelectorAll(`td.${col}, th.${col}`);
            cells.forEach(cell => {
                cell.style.display = isVisible ? '' : 'none';
            });
        }
    });
}

// ========== 13. BULK SELECT WITH CHECKBOX ==========
let selectedRows = new Set();

function toggleSelectAll(checkbox) {
    const rowCheckboxes = document.querySelectorAll('.row-select');
    rowCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        if (checkbox.checked) {
            selectedRows.add(cb.dataset.id);
        } else {
            selectedRows.clear();
        }
    });
    updateBulkActionsBar();
}

function toggleRowSelect(checkbox, reservationId) {
    if (checkbox.checked) {
        selectedRows.add(reservationId);
    } else {
        selectedRows.delete(reservationId);
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
    }
    updateBulkActionsBar();
}

function updateBulkActionsBar() {
    const count = selectedRows.size;
    let bar = document.querySelector('.bulk-actions-bar');
    
    if (count > 0 && !bar) {
        bar = document.createElement('div');
        bar.className = 'bulk-actions-bar';
        bar.innerHTML = `
            <span>📋 <span id="selectedCount">${count}</span> reservation(s) selected</span>
            <button onclick="bulkMarkPaid()" class="btn btn-success">✓ Mark as Paid</button>
            <button onclick="bulkWhatsApp()" class="btn btn-info">📱 Send WhatsApp</button>
            <button onclick="bulkPrint()" class="btn btn-primary">🖨️ Print Tickets</button>
            <button onclick="clearSelection()" class="btn btn-secondary">✗ Clear</button>
        `;
        document.body.appendChild(bar);
    } else if (bar) {
        const countSpan = bar.querySelector('#selectedCount');
        if (countSpan) countSpan.innerText = count;
        
        if (count === 0) {
            bar.remove();
        }
    }
}

function clearSelection() {
    selectedRows.clear();
    document.querySelectorAll('.row-select').forEach(cb => {
        cb.checked = false;
    });
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateBulkActionsBar();
    showNotification('Selection cleared', 'info');
}

function bulkMarkPaid() {
    if (selectedRows.size === 0) return;
    
    const password = prompt('⚠️ SECURITY VERIFICATION\n\nEnter admin password to mark selected reservations as paid:\n(Default: AdminDelete2026)');
    
    if (password !== 'AdminDelete2026') {
        showNotification('❌ Invalid password!', 'error');
        return;
    }
    
    showLoading(`Marking ${selectedRows.size} reservations as paid...`);
    
    const ids = Array.from(selectedRows);
    
    fetch('bulk_update_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            ids: ids,
            status: 'paid',
            password: password
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(`✅ ${data.updated} reservations marked as paid!`, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('❌ Error: ' + data.error, 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('❌ Error: ' + error.message, 'error');
    });
}

function bulkWhatsApp() {
    if (selectedRows.size === 0) return;
    const ids = Array.from(selectedRows).join(',');
    window.location.href = `bulk_whatsapp.php?ids=${ids}`;
}

function bulkPrint() {
    if (selectedRows.size === 0) return;
    const ids = Array.from(selectedRows).join(',');
    window.open(`print_bulk_tickets.php?ids=${ids}`, '_blank');
}

// ========== 15. SOUND NOTIFICATION FOR NEW BOOKING ==========
let lastCheckCount = 0;
let notificationSound = null;
let soundEnabled = localStorage.getItem('soundEnabled') === 'true';

// Create audio element
function initNotificationSound() {
    try {
        notificationSound = new Audio();
        // Create a simple beep using Web Audio API as fallback
        notificationSound.src = 'data:audio/wav;base64,U3RlYWx0aCBMYWJz';
    } catch(e) {
        console.log('Audio not supported');
    }
}

function playNotificationSound() {
    if (!soundEnabled) return;
    
    try {
        // Try Web Audio API for beep
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 880;
        gainNode.gain.value = 0.3;
        
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.00001, audioContext.currentTime + 0.5);
        oscillator.stop(audioContext.currentTime + 0.5);
        
        // Resume audio context if suspended
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
    } catch(e) {
        // Fallback - use a simple beep if available
        try {
            const beep = new Audio();
            beep.src = 'https://www.soundjay.com/misc/sounds/bell-ringing-05.mp3';
            beep.play();
        } catch(e2) {}
    }
}

function showSoundNotification(message) {
    const notification = document.createElement('div');
    notification.className = 'sound-notification';
    notification.innerHTML = `
        <span>🔔</span>
        <span>${message}</span>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => notification.remove(), 500);
    }, 5000);
}

function checkNewReservations() {
    fetch('check_new_reservations.php')
        .then(response => response.json())
        .then(data => {
            if (data.new_count > 0 && data.new_count > lastCheckCount) {
                playNotificationSound();
                showSoundNotification(`🎫 ${data.new_count} new reservation(s) just arrived!`);
            }
            lastCheckCount = data.total_count;
        })
        .catch(error => console.log('Error checking new reservations:', error));
}

function toggleSound() {
    soundEnabled = !soundEnabled;
    localStorage.setItem('soundEnabled', soundEnabled);
    const soundBtn = document.getElementById('soundToggle');
    if (soundBtn) {
        soundBtn.innerHTML = soundEnabled ? '🔊 Sound On' : '🔇 Sound Off';
    }
    showNotification(`Sound notifications ${soundEnabled ? 'enabled' : 'disabled'}`, 'info');
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `sound-notification`;
    notification.style.background = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
    notification.innerHTML = `<span>${type === 'success' ? '✓' : (type === 'error' ? '✗' : 'ℹ')}</span><span>${message}</span>`;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => notification.remove(), 500);
    }, 3000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initNotificationSound();
    loadColumnPreferences();
    
    // Start checking for new reservations every 30 seconds
    setInterval(checkNewReservations, 30000);
    
    // Close column menu when clicking outside
    document.addEventListener('click', function(event) {
        const menu = document.getElementById('columnToggleMenu');
        const btn = document.querySelector('.column-toggle-btn');
        if (menu && btn && !btn.contains(event.target) && !menu.contains(event.target)) {
            menu.classList.remove('show');
        }
    });
});

// Export functions for global use
window.showLoading = showLoading;
window.hideLoading = hideLoading;
window.deleteReservation = deleteReservation;
window.toggleColumnMenu = toggleColumnMenu;
window.toggleColumn = toggleColumn;
window.toggleSelectAll = toggleSelectAll;
window.toggleRowSelect = toggleRowSelect;
window.bulkMarkPaid = bulkMarkPaid;
window.bulkWhatsApp = bulkWhatsApp;
window.bulkPrint = bulkPrint;
window.clearSelection = clearSelection;
window.toggleSound = toggleSound;
window.showNotification = showNotification;