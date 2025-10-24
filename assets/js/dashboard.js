/**
 * Dashboard JavaScript - Created by AI Assistant
 * Controls sidebar toggle, animations, dropdowns, and other interactive elements
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize sidebar state from localStorage if available
    const sidebarState = localStorage.getItem('sidebarToggled');
    const sidebar = document.querySelector('.sidebar');
    const pageContent = document.querySelector('.page-content');
    
    if (sidebarState === 'true') {
        sidebar.classList.add('toggled');
        pageContent.classList.add('expanded');
    }
    
    // Initialize animation for elements
    initAnimations();
    
    // Setup event listeners
    setupEventListeners();

    // Handle alerts auto-dismiss
    setupAlertDismiss();
});

/**
 * Initialize animations for elements with animate-fadeIn class
 */
function initAnimations() {
    document.querySelectorAll('.animate-fadeIn').forEach(element => {
        // Force browser to acknowledge the element for animation
        void element.offsetWidth;
    });
}

/**
 * Setup all event listeners for interactive elements
 */
function setupEventListeners() {
    // Toggle sidebar
    const toggleButton = document.querySelector('.toggle-sidebar');
    if (toggleButton) {
        toggleButton.addEventListener('click', toggleSidebar);
    }

    // Mobile sidebar toggle
    const mobileToggleButton = document.querySelector('.mobile-toggle-sidebar');
    if (mobileToggleButton) {
        mobileToggleButton.addEventListener('click', toggleMobileSidebar);
    }

    // User dropdown toggle
    const userDropdownToggle = document.querySelector('.user-dropdown-toggle');
    if (userDropdownToggle) {
        userDropdownToggle.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelector('.user-dropdown').classList.toggle('show');
        });
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        const dropdown = document.querySelector('.user-dropdown');
        const dropdownToggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && dropdownToggle) {
            if (!dropdown.contains(e.target) && !dropdownToggle.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        }
    });

    // Card hover effects
    document.querySelectorAll('.card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.boxShadow = '0 0.5rem 2rem 0 rgba(58, 59, 69, 0.15)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = '';
            this.style.boxShadow = '';
        });
    });
}

/**
 * Toggle sidebar expanded/collapsed state
 */
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const pageContent = document.querySelector('.page-content');
    
    sidebar.classList.toggle('toggled');
    pageContent.classList.toggle('expanded');
    
    // Store preference
    localStorage.setItem('sidebarToggled', sidebar.classList.contains('toggled'));
}

/**
 * Toggle sidebar for mobile devices
 */
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('mobile-toggled');
}

/**
 * Set up auto-dismissing alerts
 */
function setupAlertDismiss() {
    const alerts = document.querySelectorAll('.alert:not(.alert-persistent)');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.classList.add('fade-out');
            setTimeout(() => {
                alert.remove();
            }, 500);
        }, 5000);
    });
}

/**
 * Create an element with animation classes
 * @param {string} tagName - HTML tag name
 * @param {string} className - Class names to add
 * @param {number} delay - Animation delay index (1-5)
 * @param {string} text - Inner text content
 * @returns {HTMLElement} The created element
 */
function createAnimatedElement(tagName, className, delay, text = '') {
    const element = document.createElement(tagName);
    element.className = `${className} animate-fadeIn delay-${delay}`;
    if (text) {
        element.textContent = text;
    }
    return element;
}

/**
 * Get the first character of each word in a string
 * @param {string} name - The name to get initials from
 * @returns {string} The initials
 */
function getInitials(name) {
    return name.split(' ')
        .map(word => word.charAt(0))
        .join('')
        .toUpperCase()
        .substring(0, 2);
}

/**
 * Create a toast notification
 * @param {string} message - The message to display
 * @param {string} type - The type of toast (success, danger, warning, info)
 * @param {number} duration - How long to show the toast in ms
 */
function showToast(message, type = 'info', duration = 3000) {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        const newContainer = document.createElement('div');
        newContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(newContainer);
    }
    
    const toast = document.createElement('div');
    toast.className = `toast show bg-${type} text-white`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
    toast.innerHTML = `
        <div class="toast-header bg-${type} text-white">
            <i class="bi bi-bell-fill me-2"></i>
            <strong class="me-auto">Notification</strong>
            <small>Just now</small>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
            ${message}
        </div>
    `;
    
    document.querySelector('.toast-container').appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => {
            toast.remove();
        }, 500);
    }, duration);
}

/**
 * Create a loading spinner overlay
 * @param {string} targetSelector - The selector for the element to overlay
 * @param {string} message - Optional message to display
 * @returns {HTMLElement} The created spinner element
 */
function showSpinner(targetSelector = 'body', message = 'Loading...') {
    const target = document.querySelector(targetSelector);
    const spinner = document.createElement('div');
    spinner.className = 'spinner-overlay';
    spinner.innerHTML = `
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">${message}</p>
        </div>
    `;
    
    target.appendChild(spinner);
    return spinner;
}

/**
 * Remove a loading spinner
 * @param {HTMLElement} spinner - The spinner element to remove
 */
function hideSpinner(spinner) {
    if (spinner) {
        spinner.classList.add('fade-out');
        setTimeout(() => {
            spinner.remove();
        }, 300);
    }
} 