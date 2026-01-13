/**
 * Printer Status Indicator
 * Shows current printer and connection status
 */
(function() {
    'use strict';

    class PrinterStatus {
        constructor(options = {}) {
            this.options = {
                position: 'bottom-right',
                showPrinterName: true,
                showConnection: true,
                autoHide: false,
                hideDelay: 3000,
                updateInterval: 5000,
                zIndex: 9998,
                ...options
            };

            this.element = null;
            this.printerName = null;
            this.isConnected = false;
            this.interval = null;

            this.init();
        }

        init() {
            this.createElement();
            this.updateStatus();
            this.setupEventListeners();

            if (!this.options.autoHide) {
                this.show();
            }

            // Start update interval
            this.startUpdating();
        }

        createElement() {
            this.element = document.createElement('div');
            this.element.className = 'qz-printer-status';
            this.element.style.cssText = `
                position: fixed;
                z-index: ${this.options.zIndex};
                padding: 8px 16px;
                background: rgba(0, 0, 0, 0.8);
                color: white;
                border-radius: 20px;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 13px;
                opacity: 0;
                transition: all 0.3s ease;
                pointer-events: none;
                backdrop-filter: blur(10px);
                display: flex;
                align-items: center;
                gap: 8px;
                max-width: 300px;
            `;

            // Set position
            this.setPosition();

            // Create status icon
            const icon = document.createElement('span');
            icon.className = 'status-icon';
            icon.textContent = '🖨️';
            icon.style.cssText = 'font-size: 14px;';

            // Create status text container
            const textContainer = document.createElement('div');
            textContainer.className = 'status-text';
            textContainer.style.cssText = `
                display: flex;
                flex-direction: column;
                gap: 2px;
            `;

            // Connection status
            const connectionSpan = document.createElement('span');
            connectionSpan.className = 'connection-status';
            connectionSpan.style.cssText = 'font-size: 11px; opacity: 0.8;';

            // Printer name
            const printerSpan = document.createElement('span');
            printerSpan.className = 'printer-name';
            printerSpan.style.cssText = 'font-weight: 500;';

            textContainer.appendChild(connectionSpan);
            textContainer.appendChild(printerSpan);

            this.element.appendChild(icon);
            this.element.appendChild(textContainer);

            document.body.appendChild(this.element);
        }

        setPosition() {
            const positions = {
                'top-left': { top: '20px', left: '20px' },
                'top-right': { top: '20px', right: '20px' },
                'bottom-left': { bottom: '20px', left: '20px' },
                'bottom-right': { bottom: '20px', right: '20px' },
                'top-center': { top: '20px', left: '50%', transform: 'translateX(-50%)' },
                'bottom-center': { bottom: '20px', left: '50%', transform: 'translateX(-50%)' },
            };

            const pos = positions[this.options.position] || positions['bottom-right'];
            Object.assign(this.element.style, pos);
        }

        async updateStatus() {
            try {
                // Check connection
                if (window.SmartPrint) {
                    this.isConnected = window.SmartPrint.isConnected();

                    // Get current printer
                    if (window.SmartPrint.getCurrentPrinter) {
                        this.printerName = await window.SmartPrint.getCurrentPrinter();
                    }
                }

                this.updateDisplay();
            } catch (error) {
                console.error('Failed to update printer status:', error);
            }
        }

        updateDisplay() {
            if (!this.element) return;

            const connectionSpan = this.element.querySelector('.connection-status');
            const printerSpan = this.element.querySelector('.printer-name');

            // Update connection status
            if (this.options.showConnection && connectionSpan) {
                const statusText = this.isConnected ? '🟢 Connected' : '🔴 Disconnected';
                connectionSpan.textContent = statusText;
            } else if (connectionSpan) {
                connectionSpan.textContent = '';
            }

            // Update printer name
            if (this.options.showPrinterName && printerSpan) {
                if (this.printerName) {
                    printerSpan.textContent = this.printerName;
                } else {
                    printerSpan.textContent = 'No printer selected';
                    printerSpan.style.opacity = '0.6';
                }
            } else if (printerSpan) {
                printerSpan.textContent = '';
            }

            // Update icon based on connection
            const icon = this.element.querySelector('.status-icon');
            if (icon) {
                icon.textContent = this.isConnected ? '🖨️' : '❌';
            }
        }

        setupEventListeners() {
            // Listen for QZ events
            if (window.SmartPrint) {
                window.SmartPrint.on('connected', () => {
                    this.isConnected = true;
                    this.updateDisplay();
                    this.show();
                });

                window.SmartPrint.on('disconnected', () => {
                    this.isConnected = false;
                    this.updateDisplay();
                    this.show();
                });

                window.SmartPrint.on('printer-saved', (data) => {
                    this.printerName = data.printer;
                    this.updateDisplay();
                    this.show();
                });

                window.SmartPrint.on('printers-loaded', () => {
                    this.updateStatus();
                });
            }

            // Click to show printer switcher
            if (this.options.clickToSwitch) {
                this.element.style.cursor = 'pointer';
                this.element.style.pointerEvents = 'auto';

                this.element.addEventListener('click', () => {
                    if (window.SmartPrint && window.SmartPrint.showPrinterSwitcher) {
                        window.SmartPrint.showPrinterSwitcher();
                    }
                });
            }
        }

        startUpdating() {
            if (this.interval) {
                clearInterval(this.interval);
            }

            this.interval = setInterval(() => {
                this.updateStatus();
            }, this.options.updateInterval);
        }

        show() {
            if (!this.element) return;

            this.element.style.opacity = '1';
            this.element.style.transform = 'translateY(0)';

            if (this.options.autoHide) {
                setTimeout(() => this.hide(), this.options.hideDelay);
            }
        }

        hide() {
            if (!this.element) return;

            this.element.style.opacity = '0';
            this.element.style.transform = 'translateY(10px)';
        }

        toggle() {
            const currentOpacity = parseFloat(this.element.style.opacity) || 0;
            if (currentOpacity > 0) {
                this.hide();
            } else {
                this.show();
            }
        }

        destroy() {
            if (this.interval) {
                clearInterval(this.interval);
            }

            if (this.element && this.element.parentNode) {
                this.element.parentNode.removeChild(this.element);
            }
        }
    }

    // Auto-initialize if data attribute is present
    document.addEventListener('DOMContentLoaded', () => {
        const elements = document.querySelectorAll('[data-qz-status]');

        elements.forEach(element => {
            const options = JSON.parse(element.dataset.qzStatus || '{}');
            new PrinterStatus(options);
        });
    });

    // Export
    window.PrinterStatus = PrinterStatus;

})();
