// Global Variables
let searchTimeout;
let currentSlideIndex = 0;
const API_BASE_URL = window.location.origin;

// DOM Content Loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

// Initialize Application
function initializeApp() {
    setupNavigation();
    setupSearchFunctionality();
    loadFeaturedDestinations();
    animateCounters();
    setupScrollEffects();
    setupKeyboardNavigation();
}

// Navigation Setup
function setupNavigation() {
    const navToggle = document.getElementById('nav-toggle');
    const navMenu = document.getElementById('nav-menu');
    
    if (navToggle && navMenu) {
        navToggle.addEventListener('click', () => {
            navMenu.classList.toggle('active');
            navToggle.classList.toggle('active');
        });
        
        // Close mobile menu when clicking on a link
        navMenu.addEventListener('click', (e) => {
            if (e.target.classList.contains('nav-link')) {
                navMenu.classList.remove('active');
                navToggle.classList.remove('active');
            }
        });
    }
    
    // Active navigation highlighting
    updateActiveNavigation();
    window.addEventListener('scroll', updateActiveNavigation);
}

// Update Active Navigation
function updateActiveNavigation() {
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.nav-link');
    
    let current = '';
    sections.forEach(section => {
        const sectionTop = section.offsetTop;
        const sectionHeight = section.clientHeight;
        if (pageYOffset >= sectionTop - 200) {
            current = section.getAttribute('id');
        }
    });
    
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === `#${current}`) {
            link.classList.add('active');
        }
    });
}

// Search Functionality
function setupSearchFunctionality() {
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            
            if (query.length < 2) {
                hideSearchResults();
                return;
            }
            
            searchTimeout = setTimeout(() => {
                performSearchRequest(query);
            }, 300);
        });
        
        // Hide search results when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-container')) {
                hideSearchResults();
            }
        });
    }
}

// Perform Search Request
async function performSearchRequest(query) {
    const searchResults = document.getElementById('searchResults');
    if (!searchResults) return;
    
    try {
        showLoadingState(searchResults);
        
        const response = await fetch(`${API_BASE_URL}/api/search.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query: query })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        displaySearchResults(data);
        
    } catch (error) {
        console.error('Search error:', error);
        displaySearchError();
    }
}

// Quick Search Function
function quickSearch(term) {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = term;
        performSearchRequest(term);
    }
}

// Perform Search (called by search button)
function performSearch() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput && searchInput.value.trim()) {
        performSearchRequest(searchInput.value.trim());
    }
}

// Display Search Results
function displaySearchResults(data) {
    const searchResults = document.getElementById('searchResults');
    if (!searchResults) return;
    
    if (!data.results || data.results.length === 0) {
        searchResults.innerHTML = `
            <div class="search-empty">
                <i class="fas fa-search"></i>
                <p>No results found for your search.</p>
                <p class="search-suggestion">Try searching for destinations like "Pristina", "Tirana", or "Albanian Alps"</p>
            </div>
        `;
    } else {
        searchResults.innerHTML = `
            <div class="search-results-header">
                <h3>Search Results (${data.results.length})</h3>
            </div>
            <div class="search-results-list">
                ${data.results.map(result => `
                    <div class="search-result-item" onclick="openResultDetails('${result.type}', ${result.id})">
                        <div class="result-icon">
                            <i class="${getResultIcon(result.type)}"></i>
                        </div>
                        <div class="result-content">
                            <h4>${escapeHtml(result.name)}</h4>
                            <p>${escapeHtml(result.description)}</p>
                            <span class="result-type">${capitalizeFirst(result.type)}</span>
                        </div>
                        <div class="result-meta">
                            ${result.rating ? `
                                <div class="result-rating">
                                    <i class="fas fa-star"></i>
                                    <span>${result.rating}</span>
                                </div>
                            ` : ''}
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    searchResults.style.display = 'block';
    searchResults.classList.add('fade-in');
}

// Display Search Error
function displaySearchError() {
    const searchResults = document.getElementById('searchResults');
    if (!searchResults) return;
    
    searchResults.innerHTML = `
        <div class="search-error">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Unable to perform search at this time.</p>
            <p class="error-suggestion">Please check your connection and try again.</p>
        </div>
    `;
    searchResults.style.display = 'block';
}

// Show Loading State
function showLoadingState(element) {
    element.innerHTML = `
        <div class="search-loading">
            <div class="loading-spinner"></div>
            <p>Searching...</p>
        </div>
    `;
    element.style.display = 'block';
}

// Hide Search Results
function hideSearchResults() {
    const searchResults = document.getElementById('searchResults');
    if (searchResults) {
        searchResults.style.display = 'none';
        searchResults.classList.remove('fade-in');
    }
}

// Get Result Icon
function getResultIcon(type) {
    const icons = {
        destination: 'fas fa-map-marker-alt',
        accommodation: 'fas fa-bed',
        restaurant: 'fas fa-utensils',
        activity: 'fas fa-hiking',
        historical: 'fas fa-landmark',
        cultural: 'fas fa-theater-masks'
    };
    return icons[type] || 'fas fa-info-circle';
}

// Open Result Details
function openResultDetails(type, id) {
    const urls = {
        destination: 'pages/destinations.html',
        accommodation: 'pages/accommodations.html',
        historical: 'pages/history.html',
        cultural: 'pages/culture.html'
    };
    
    const url = urls[type] || 'pages/destinations.html';
    window.location.href = `${url}?id=${id}`;
}

// Load Featured Destinations
async function loadFeaturedDestinations() {
    const destinationsSlider = document.getElementById('destinationsSlider');
    if (!destinationsSlider) return;
    
    try {
        const response = await fetch(`${API_BASE_URL}/api/destinations.php?featured=true`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.destinations && data.destinations.length > 0) {
            displayDestinations(data.destinations);
        } else {
            displayEmptyDestinations();
        }
        
    } catch (error) {
        console.error('Error loading destinations:', error);
        displayDestinationsError();
    }
}

// Display Destinations
function displayDestinations(destinations) {
    const destinationsSlider = document.getElementById('destinationsSlider');
    if (!destinationsSlider) return;
    
    destinationsSlider.innerHTML = destinations.map(destination => `
        <div class="destination-card" onclick="viewDestination(${destination.id})">
            <div class="destination-image">
                <i class="${getDestinationIcon(destination.category)}"></i>
            </div>
            <div class="destination-content">
                <h3>${escapeHtml(destination.name)}</h3>
                <p>${escapeHtml(destination.description)}</p>
                <div class="destination-meta">
                    <span class="destination-location">
                        <i class="fas fa-map-marker-alt"></i>
                        ${escapeHtml(destination.location)}
                    </span>
                    ${destination.rating ? `
                        <div class="destination-rating">
                            <i class="fas fa-star"></i>
                            <span>${destination.rating}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `).join('');
}

// Display Empty Destinations
function displayEmptyDestinations() {
    const destinationsSlider = document.getElementById('destinationsSlider');
    if (!destinationsSlider) return;
    
    destinationsSlider.innerHTML = `
        <div class="empty-state">
            <i class="fas fa-map"></i>
            <h3>No Featured Destinations Available</h3>
            <p>Featured destinations will appear here once they are added to the database.</p>
            <button class="btn btn-primary" onclick="window.location.href='pages/destinations.html'">
                View All Destinations
            </button>
        </div>
    `;
}

// Display Destinations Error
function displayDestinationsError() {
    const destinationsSlider = document.getElementById('destinationsSlider');
    if (!destinationsSlider) return;
    
    destinationsSlider.innerHTML = `
        <div class="error-state">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Unable to Load Destinations</h3>
            <p>There was an error loading the featured destinations. Please try again later.</p>
            <button class="btn btn-secondary" onclick="loadFeaturedDestinations()">
                Retry
            </button>
        </div>
    `;
}

// Get Destination Icon
function getDestinationIcon(category) {
    const icons = {
        mountain: 'fas fa-mountain',
        city: 'fas fa-city',
        historical: 'fas fa-landmark',
        nature: 'fas fa-tree',
        cultural: 'fas fa-theater-masks',
        religious: 'fas fa-place-of-worship',
        coastal: 'fas fa-water'
    };
    return icons[category] || 'fas fa-map-marker-alt';
}

// View Destination
function viewDestination(id) {
    window.location.href = `pages/destinations.html?id=${id}`;
}

// Animate Counters
function animateCounters() {
    const observerOptions = {
        threshold: 0.5,
        rootMargin: '0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counters = entry.target.querySelectorAll('.stat-number');
                counters.forEach(counter => {
                    animateCounter(counter);
                });
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    const statsSection = document.querySelector('.hero-stats');
    if (statsSection) {
        observer.observe(statsSection);
    }
}

// Animate Individual Counter
function animateCounter(element) {
    const target = parseInt(element.getAttribute('data-target'));
    const duration = 2000;
    const start = Date.now();
    
    function updateCounter() {
        const elapsed = Date.now() - start;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function for smooth animation
        const easeOutQuart = 1 - Math.pow(1 - progress, 4);
        const current = Math.floor(easeOutQuart * target);
        
        element.textContent = current;
        
        if (progress < 1) {
            requestAnimationFrame(updateCounter);
        } else {
            element.textContent = target;
        }
    }
    
    updateCounter();
}

// Setup Scroll Effects
function setupScrollEffects() {
    // Parallax effect for hero section
    window.addEventListener('scroll', () => {
        const scrolled = window.pageYOffset;
        const hero = document.querySelector('.hero');
        
        if (hero) {
            const rate = scrolled * -0.5;
            hero.style.transform = `translateY(${rate}px)`;
        }
    });
    
    // Fade in animations for sections
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('fade-in');
            }
        });
    }, observerOptions);
    
    // Observe sections for animation
    const sections = document.querySelectorAll('.services, .featured-destinations, .search-section');
    sections.forEach(section => {
        observer.observe(section);
    });
}

// Setup Keyboard Navigation
function setupKeyboardNavigation() {
    document.addEventListener('keydown', (e) => {
        // ESC key to close modals
        if (e.key === 'Escape') {
            closeEmergencyModal();
            hideSearchResults();
        }
        
        // Enter key in search input
        if (e.key === 'Enter' && e.target.id === 'searchInput') {
            e.preventDefault();
            performSearch();
        }
        
        // Ctrl/Cmd + K for search focus
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });
}

// Emergency Modal Functions
function openEmergencyModal() {
    const modal = document.getElementById('emergencyModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Focus trap
        const firstFocusable = modal.querySelector('.close');
        if (firstFocusable) {
            firstFocusable.focus();
        }
    }
}

function closeEmergencyModal() {
    const modal = document.getElementById('emergencyModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Transportation Info Modal
function showTransportationInfo() {
    const modal = createModal('Transportation Information', `
        <div class="transport-info">
            <div class="transport-section">
                <h4><i class="fas fa-plane"></i> Airports</h4>
                <ul>
                    <li><strong>Pristina Airport (PRN)</strong> - Main international airport for Kosovo</li>
                    <li><strong>Tirana Airport (TIA)</strong> - Main international airport for Albania</li>
                </ul>
            </div>
            
            <div class="transport-section">
                <h4><i class="fas fa-bus"></i> Public Transport</h4>
                <ul>
                    <li>Regular bus services between major cities</li>
                    <li>Local bus networks in Pristina and Tirana</li>
                    <li>Shared taxis (furgons) for shorter routes</li>
                </ul>
            </div>
            
            <div class="transport-section">
                <h4><i class="fas fa-car"></i> Car Rental</h4>
                <ul>
                    <li>International car rental agencies available</li>
                    <li>Valid EU driving license accepted</li>
                    <li>Road conditions generally good on main routes</li>
                </ul>
            </div>
            
            <div class="transport-section">
                <h4><i class="fas fa-train"></i> Rail</h4>
                <ul>
                    <li>Limited rail services in Kosovo</li>
                    <li>Regular train connections within Albania</li>
                    <li>Cross-border connections available</li>
                </ul>
            </div>
        </div>
    `);
}

// Utility Functions
function scrollToSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
        section.scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
    }
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, (m) => map[m]);
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function createModal(title, content) {
    // Remove existing custom modal if any
    const existingModal = document.getElementById('customModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create new modal
    const modal = document.createElement('div');
    modal.id = 'customModal';
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>${title}</h3>
                <span class="close" onclick="closeCustomModal()">&times;</span>
            </div>
            <div class="modal-body">
                ${content}
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    return modal;
}

function closeCustomModal() {
    const modal = document.getElementById('customModal');
    if (modal) {
        modal.remove();
        document.body.style.overflow = 'auto';
    }
}

// Error Handling
window.addEventListener('error', (event) => {
    console.error('Global error:', event.error);
});

window.addEventListener('unhandledrejection', (event) => {
    console.error('Unhandled promise rejection:', event.reason);
});

// Click outside modal to close
window.addEventListener('click', (event) => {
    const emergencyModal = document.getElementById('emergencyModal');
    const customModal = document.getElementById('customModal');
    
    if (event.target === emergencyModal) {
        closeEmergencyModal();
    }
    
    if (event.target === customModal) {
        closeCustomModal();
    }
});

// Service Worker Registration (for future PWA capabilities)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Service worker registration would go here for offline functionality
        console.log('Service Worker support detected');
    });
}

// Performance Monitoring
if ('performance' in window) {
    window.addEventListener('load', () => {
        setTimeout(() => {
            const perfData = performance.timing;
            const loadTime = perfData.loadEventEnd - perfData.navigationStart;
            console.log(`Page load time: ${loadTime}ms`);
        }, 0);
    });
}
