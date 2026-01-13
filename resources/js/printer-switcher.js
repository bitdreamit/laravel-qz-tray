/**
 * Printer Switcher Component
 * Provides UI for switching printers
 */
(function() {
    'use strict';

    class PrinterSwitcher {
        constructor(options = {}) {
            this.options = {
                position: 'top-right',
                showOnHover: false,
                autoHide: true,
                hideDelay: 3000,
                zIndex: 9999,
                ...options
            };

            this.container = null;
            this.selectElement = null;
            this.isVisible = false;
            this.printers = [];

            this.init();
        }

        async init() {
            this.createElement();
            this.loadPrinters();
            this.setupEventListeners();

            if (!this.options.autoHide) {
                this.show();
            }
        }

        createElement() {
            this.container = document.createElement('div');
            this.container.className = 'printer-switcher-container';
            this.container.style.cssText = `
                position: fixed;
                z-index: ${this.options.zIndex};
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 12px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                transition: all 0.3s ease;
                min-width: 250px;
            `;

            // Set position
            this.setPosition();

            // Create select element
            this.selectElement = document.createElement('select');
            this.selectElement.className = 'printer-select';
            this.selectElement.style.cssText = `
                width: 100%;
                padding: 8px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 14px;
                margin-bottom: 10px;
            `;

            // Create label
            const label = document.createElement('div');
            label.className = 'printer-label';
            label.textContent = 'Select Printer:';
            label.style.cssText = `
                font-weight: 600;
                margin-bottom: 8px;
                color: #333;
            `;

            // Create buttons container
            const buttons = document.createElement('div');
            buttons.className = 'printer-buttons';
            buttons.style.cssText = `
                display: flex;
                gap: 8px;
                margin-top: 10px;
            `;

            // Apply button
            const applyBtn = document.createElement('button');
            applyBtn.className = 'btn-apply';
            applyBtn.textContent = 'Apply';
            applyBtn.style.cssText = `
                flex: 1;
                padding: 8px;
                background: #4CAF50;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 500;
            `;

            // Cancel button
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-cancel';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.style.cssText = `
                flex: 1;
                padding: 8px;
                background: #f5f5f5;
                color: #666;
                border: 1px solid #ddd;
                border-radius: 4px;
                cursor: pointer;
            `;

            // Assemble
            buttons.appendChild(applyBtn);
            buttons.appendChild(cancelBtn);

            this.container.appendChild(label);
            this.container.appendChild(this.selectElement);
            this.container.appendChild(buttons);

            document.body.appendChild(this.container);

            // Set initial state
            this.hide();
        }

        setPosition() {
            const positions = {
                'top-left': { top: '20px', left: '20px' },
                'top-right': { top: '20px', right: '20px' },
                'bottom-left': { bottom: '20px', left: '20px' },
                'bottom-right': { bottom: '20px', right: '20px' },
                'center': {
                    top: '50%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)'
                },
            };

            const pos = positions[this.options.position] || positions['top-right'];
            Object.assign(this.container.style, pos);
        }

        async loadPrinters() {
            try {
                if (window.SmartPrint && window.SmartPrint.getPrinters) {
                    this.printers = await window.SmartPrint.getPrinters();
                    this.updateSelect();
                }
            } catch (error) {
                console.error('Failed to load printers:', error);
            }
        }

        updateSelect() {
            this.selectElement.innerHTML = '';

            // Add default option
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = '-- Select Printer --';
            this.selectElement.appendChild(defaultOption);

            // Add printers
            this.printers.forEach(printer => {
                const option = document.createElement('option');
                const printerName = typeof printer === 'string' ? printer : printer.name;
                option.value = printerName;
                option.textContent = printerName;
                this.selectElement.appendChild(option);
            });

            // Try to select current printer
            this.selectCurrentPrinter();
        }

        async selectCurrentPrinter() {
            try {
                if (window.SmartPrint && window.SmartPrint.getCurrentPrinter) {
                    const current = await window.SmartPrint.getCurrentPrinter();
                    if (current) {
                        this.selectElement.value = current;
                    }
                }
            } catch (error) {
                // Ignore error
            }
        }

        setupEventListeners() {
            const applyBtn = this.container.querySelector('.btn-apply');
            const cancelBtn = this.container.querySelector('.btn-cancel');

            applyBtn.addEventListener('click', () => this.applyPrinter());
            cancelBtn.addEventListener('click', () => this.hide());

            // Close on escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isVisible) {
                    this.hide();
                }
            });

            // Close on click outside
            document.addEventListener('click', (e) => {
                if (this.isVisible && !this.container.contains(e.target)) {
                    this.hide();
                }
            });

            // Show on hotkey
            if (this.options.showOnHotkey) {
                document.addEventListener('keydown', (e) => {
                    if (e.ctrlKey && e.shiftKey && e.key === 'P') {
                        e.preventDefault();
                        this.show();
                    }
                });
            }
        }

        async applyPrinter() {
            const selectedPrinter = this.selectElement.value;

            if (!selectedPrinter) {
                alert('Please select a printer');
                return;
            }

            try {
                if (window.SmartPrint && window.SmartPrint.setPrinter) {
                    await window.SmartPrint.setPrinter(selectedPrinter);

                    // Show success message
                    this.showMessage('Printer saved successfully', 'success');

                    // Hide after delay
                    if (this.options.autoHide) {
                        setTimeout(() => this.hide(), this.options.hideDelay);
                    }
                }
            } catch (error) {
                this.showMessage('Failed to save printer', 'error');
                console.error('Failed to set printer:', error);
            }
        }

        showMessage(message, type = 'info') {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'printer-message';
            messageDiv.textContent = message;
            messageDiv.style.cssText = `
                margin-top: 10px;
                padding: 8px;
                border-radius: 4px;
                font-size: 12px;
                text-align: center;
                background: ${type === 'success' ? '#d4edda' : '#f8d7da'};
                color: ${type === 'success' ? '#155724' : '#721c24'};
                border: 1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'};
            `;

            // Remove existing message
            const existing = this.container.querySelector('.printer-message');
            if (existing) {
                existing.remove();
            }

            this.container.appendChild(messageDiv);

            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.remove();
                }
            }, 3000);
        }

        show() {
            this.container.style.opacity = '1';
            this.container.style.transform = 'translateY(0)';
            this.container.style.pointerEvents = 'auto';
            this.isVisible = true;

            // Refresh printers
            this.loadPrinters();
        }

        hide() {
            this.container.style.opacity = '0';
            this.container.style.transform = 'translateY(-10px)';
            this.container.style.pointerEvents = 'none';
            this.isVisible = false;
        }

        toggle() {
            if (this.isVisible) {
                this.hide();
            } else {
                this.show();
            }
        }

        destroy() {
            if (this.container && this.container.parentNode) {
                this.container.parentNode.removeChild(this.container);
            }
        }
    }

    // Auto-initialize if data attribute is present
    document.addEventListener('DOMContentLoaded', () => {
        const elements = document.querySelectorAll('[data-qz-switcher]');

        elements.forEach(element => {
            const options = JSON.parse(element.dataset.qzSwitcher || '{}');
            const switcher = new PrinterSwitcher(options);

            // If element is a button, add click handler
            if (element.tagName === 'BUTTON') {
                element.addEventListener('click', () => switcher.toggle());
            }
        });
    });

    // Export
    window.PrinterSwitcher = PrinterSwitcher;

})();
