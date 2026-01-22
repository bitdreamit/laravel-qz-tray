<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>QZ Tray Test - Laravel v2.2.6 Compatible</title>

    <!-- Bootstrap 4.6 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }

        .status-connected {
            background-color: #28a745;
            box-shadow: 0 0 10px #28a745;
        }

        .status-disconnected {
            background-color: #dc3545;
        }

        .status-connecting {
            background-color: #ffc107;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
            padding: 15px 20px;
        }

        .log-container {
            background: #1a1a1a;
            color: #f0f0f0;
            border-radius: 5px;
            padding: 15px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 5px 10px;
            border-bottom: 1px solid #333;
            margin-bottom: 3px;
            line-height: 1.4;
        }

        .log-timestamp {
            color: #888;
            font-size: 11px;
            margin-right: 10px;
        }

        .log-info { color: #17a2b8; }
        .log-success { color: #28a745; }
        .log-warning { color: #ffc107; }
        .log-error { color: #dc3545; }

        .printer-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .printer-item:hover {
            border-color: #667eea;
            background: #e9ecef;
        }

        .printer-item.active {
            border-color: #28a745;
            background: #d4edda;
        }

        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            transition: all 0.3s;
        }

        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            color: white;
        }

        .connection-status {
            padding: 10px 15px;
            border-radius: 5px;
            font-weight: 500;
            margin-bottom: 15px;
        }

        .connected-bg {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .disconnected-bg {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .connecting-bg {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .tab-content {
            border: 1px solid #dee2e6;
            border-top: none;
            padding: 20px;
            background: white;
            border-radius: 0 0 5px 5px;
        }

        .nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: #6c757d;
            font-weight: 500;
            padding: 12px 20px;
        }

        .nav-tabs .nav-link.active {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            background: transparent;
        }

        .nav-tabs .nav-link:hover {
            color: #667eea;
        }

        .progress-thin {
            height: 5px;
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 0;
        }

        .install-guide {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            border-radius: 0 5px 5px 0;
        }

        .install-guide h6 {
            color: #856404;
            margin-bottom: 10px;
        }

        .test-section {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px 0;
            border-radius: 0 5px 5px 0;
        }

        .test-section h6 {
            color: #0d47a1;
            margin-bottom: 10px;
        }

        .version-badge {
            background: #6c757d;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-left: 5px;
        }

        .test-print-btn {
            margin: 5px;
            min-width: 120px;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="font-weight-bold">
                        <i class="fas fa-print text-primary mr-2"></i>
                        QZ Tray Test Console
                    </h1>
                    <p class="text-muted mb-0">
                        Laravel QZ Tray Integration • Compatible with QZ v2.2.6
                        <span class="version-badge">v3.0.3</span>
                    </p>
                </div>
                <div class="text-right">
                    <span class="badge badge-danger" id="appStatus">
                        <span class="status-indicator status-disconnected"></span>
                        Disconnected
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Connection Status Banner -->
    <div class="row">
        <div class="col-12">
            <div class="connection-status disconnected-bg" id="statusBanner">
                <i class="fas fa-times-circle mr-2"></i>
                <span id="statusText">Disconnected from QZ Tray</span>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column -->
        <div class="col-lg-4">
            <!-- Connection Panel -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plug mr-2"></i> Connection Settings
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="connectionHost">Host</label>
                        <input type="text" class="form-control" id="connectionHost" value="localhost"
                               placeholder="QZ Tray host">
                        <small class="form-text text-muted">Host where QZ Tray is running</small>
                    </div>

                    <div class="form-row mb-3">
                        <div class="col">
                            <label for="connectionPort">Port</label>
                            <select class="form-control" id="connectionPort">
                                <option value="8181" selected>8181 (HTTP - Default)</option>
                                <option value="8182">8182 (HTTPS)</option>
                                <option value="8180">8180 (Alternative)</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Secure</label>
                            <div class="custom-control custom-switch mt-2">
                                <input type="checkbox" class="custom-control-input" id="connectionSecure">
                                <label class="custom-control-label" for="connectionSecure">WSS</label>
                            </div>
                        </div>
                    </div>

                    <div class="install-guide">
                        <h6><i class="fas fa-info-circle mr-1"></i> QZ Tray Installation</h6>
                        <small>
                            1. Download QZ Tray from <a href="https://qz.io/download" target="_blank">qz.io/download</a><br>
                            2. Install and launch QZ Tray<br>
                            3. Check system tray for QZ Tray icon<br>
                            4. Click "Connect" button below
                        </small>
                    </div>

                    <div class="progress progress-thin mb-3">
                        <div class="progress-bar bg-success" id="connectionProgress"
                             role="progressbar" style="width: 0%"></div>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-gradient" onclick="connectQz()" id="connectBtn">
                            <i class="fas fa-link mr-2"></i> Connect to QZ Tray
                        </button>
                        <button class="btn btn-outline-danger" onclick="disconnectQz()" id="disconnectBtn" disabled>
                            <i class="fas fa-unlink mr-2"></i> Disconnect
                        </button>
                        <button class="btn btn-outline-info" onclick="testConnection()">
                            <i class="fas fa-vial mr-2"></i> Test Connection
                        </button>
                    </div>
                </div>
            </div>

            <!-- Printer Discovery -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-search mr-2"></i> Printer Discovery
                </div>
                <div class="card-body">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="printerSearch"
                               placeholder="Search printers...">
                        <div class="input-group-append">
                            <button class="btn btn-outline-primary" type="button" onclick="searchPrinters()">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>

                    <div class="btn-group w-100 mb-3" role="group">
                        <button class="btn btn-outline-primary" onclick="findAllPrinters()">
                            <i class="fas fa-list mr-1"></i> All Printers
                        </button>
                        <button class="btn btn-outline-primary" onclick="findDefaultPrinter()">
                            <i class="fas fa-star mr-1"></i> Default
                        </button>
                        <button class="btn btn-outline-info" onclick="refreshPrinters()">
                            <i class="fas fa-sync-alt mr-1"></i> Refresh
                        </button>
                    </div>

                    <div class="text-center mb-3">
                        <span class="badge badge-primary" id="printerCount">0 printers</span>
                    </div>

                    <div id="printerList" style="max-height: 250px; overflow-y: auto; margin-bottom: 15px;">
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-print fa-2x mb-3"></i>
                            <p>No printers found</p>
                        </div>
                    </div>

                    <div class="alert alert-secondary" id="currentPrinterDisplay">
                        <i class="fas fa-print mr-2"></i> No printer selected
                    </div>

                    <button class="btn btn-success w-100" onclick="setSelectedPrinter()" id="setPrinterBtn" disabled>
                        <i class="fas fa-check mr-2"></i> Set as Default Printer
                    </button>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-lg-8">
            <!-- Printing Panel -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-paper-plane mr-2"></i> Printing Tests
                </div>
                <div class="card-body">
                    <div class="test-section">
                        <h6><i class="fas fa-vial mr-1"></i> Quick Print Tests</h6>
                        <div class="d-flex flex-wrap mb-3">
                            <button class="btn btn-outline-success test-print-btn" onclick="testPrint()">
                                <i class="fas fa-print mr-1"></i> Test Print
                            </button>
                            <button class="btn btn-outline-primary test-print-btn" onclick="testZplPrint()">
                                <i class="fas fa-barcode mr-1"></i> ZPL Label
                            </button>
                            <button class="btn btn-outline-info test-print-btn" onclick="testHtmlPrint()">
                                <i class="fas fa-file-code mr-1"></i> HTML Print
                            </button>
                            <button class="btn btn-outline-warning test-print-btn" onclick="testRawPrint()">
                                <i class="fas fa-terminal mr-1"></i> RAW Data
                            </button>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h5>Custom Print Settings</h5>
                            <div class="form-group mb-2">
                                <label>Print Type</label>
                                <select class="form-control" id="printType">
                                    <option value="html">HTML Document</option>
                                    <option value="pdf">PDF Document</option>
                                    <option value="zpl">ZPL Label</option>
                                    <option value="raw">RAW Command</option>
                                </select>
                            </div>
                            <div class="form-row mb-2">
                                <div class="col-6">
                                    <label>Copies</label>
                                    <input type="number" class="form-control" id="printCopies" value="1" min="1" max="99">
                                </div>
                                <div class="col-6">
                                    <label>Paper Size</label>
                                    <select class="form-control" id="paperSize">
                                        <option value="A4">A4</option>
                                        <option value="Letter" selected>Letter</option>
                                        <option value="A5">A5</option>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-gradient w-100" onclick="customPrint()">
                                <i class="fas fa-play mr-2"></i> Execute Custom Print
                            </button>
                        </div>
                        <div class="col-md-6">
                            <h5>Print Queue Status</h5>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="display-4 font-weight-bold" id="queueCount">0</div>
                                            <small class="text-muted">Queued</small>
                                        </div>
                                        <div class="col-4">
                                            <div class="display-4 font-weight-bold" id="activeCount">0</div>
                                            <small class="text-muted">Active</small>
                                        </div>
                                        <div class="col-4">
                                            <div class="display-4 font-weight-bold" id="completedCount">0</div>
                                            <small class="text-muted">Completed</small>
                                        </div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger w-100 mt-3" onclick="clearQueue()">
                                        <i class="fas fa-trash mr-1"></i> Clear All Jobs
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="testData">Test Data</label>
                        <textarea class="form-control" id="testData" rows="3" placeholder="Enter custom print data here...">
This is a test print from Laravel QZ Tray Package.
Time: {{ now()->format('Y-m-d H:i:s') }}
Status: Testing QZ Tray v2.2.6 Compatibility
                        </textarea>
                    </div>
                </div>
            </div>

            <!-- Logs & Status -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-terminal mr-2"></i> Console Logs
                </div>
                <div class="card-body p-0">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs px-3 pt-3" id="logTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="events-tab" data-toggle="tab" href="#events" role="tab">
                                <i class="fas fa-bell mr-1"></i> Events
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="system-tab" data-toggle="tab" href="#system" role="tab">
                                <i class="fas fa-info-circle mr-1"></i> System Info
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="debug-tab" data-toggle="tab" href="#debug" role="tab">
                                <i class="fas fa-bug mr-1"></i> Debug
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="logTabsContent">
                        <!-- Events Tab -->
                        <div class="tab-pane fade show active" id="events" role="tabpanel">
                            <div class="log-container" id="eventLog">
                                <div class="log-entry">
                                    <span class="log-timestamp">[{{ now()->format('H:i:s') }}]</span>
                                    <span class="log-info">System initialized. Ready to test QZ Tray v2.2.6.</span>
                                </div>
                            </div>
                            <div class="mt-3 px-3 pb-3">
                                <button class="btn btn-sm btn-outline-secondary" onclick="clearEventLog()">
                                    <i class="fas fa-eraser mr-1"></i> Clear Events
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="exportLogs()">
                                    <i class="fas fa-download mr-1"></i> Export Logs
                                </button>
                                <button class="btn btn-sm btn-outline-info" onclick="copyLogs()">
                                    <i class="fas fa-copy mr-1"></i> Copy Logs
                                </button>
                            </div>
                        </div>

                        <!-- System Tab -->
                        <div class="tab-pane fade" id="system" role="tabpanel">
                            <div class="table-responsive px-3 pt-2">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%"><strong>SmartPrint Version:</strong></td>
                                        <td id="packageVersion">3.0.3</td>
                                    </tr>
                                    <tr>
                                        <td><strong>QZ Tray Version:</strong></td>
                                        <td id="qzVersion">Not detected</td>
                                    </tr>
                                    <tr>
                                        <td><strong>WebSocket Status:</strong></td>
                                        <td><span id="wsStatus" class="badge badge-danger">Disconnected</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Certificate Status:</strong></td>
                                        <td><span id="certStatus" class="badge badge-warning">Not loaded</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Printers Detected:</strong></td>
                                        <td><span id="detectedPrinters" class="badge badge-secondary">0</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Connection:</strong></td>
                                        <td id="connectionInfo">Not connected</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Last Activity:</strong></td>
                                        <td id="lastActivity">Never</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="px-3 pb-3">
                                <button class="btn btn-sm btn-outline-primary" onclick="refreshSystemInfo()">
                                    <i class="fas fa-sync-alt mr-1"></i> Refresh Info
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="testEndpoints()">
                                    <i class="fas fa-check-circle mr-1"></i> Test Endpoints
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="clearAllCache()">
                                    <i class="fas fa-trash mr-1"></i> Clear Cache
                                </button>
                            </div>
                        </div>

                        <!-- Debug Tab -->
                        <div class="tab-pane fade" id="debug" role="tabpanel">
                            <div class="px-3 pt-2">
                                <div class="btn-group mb-3" role="group">
                                    <button class="btn btn-sm btn-outline-info" onclick="testCertificate()">
                                        <i class="fas fa-certificate mr-1"></i> Test Certificate
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="testSignature()">
                                        <i class="fas fa-signature mr-1"></i> Test Signature
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning" onclick="testSimpleConnect()">
                                        <i class="fas fa-plug mr-1"></i> Simple Connect
                                    </button>
                                </div>
                                <div class="btn-group mb-3" role="group">
                                    <button class="btn btn-sm btn-outline-warning" onclick="testConnectionMethods()">
                                        <i class="fas fa-bolt mr-1"></i> Test Methods
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="debugQzApi()">
                                        <i class="fas fa-code mr-1"></i> Debug API
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="forceDisconnect()">
                                        <i class="fas fa-power-off mr-1"></i> Force Disconnect
                                    </button>
                                </div>
                            </div>
                            <div class="log-container" id="debugLog">
                                <div class="log-entry">
                                    <span class="log-timestamp">[{{ now()->format('H:i:s') }}]</span>
                                    <span class="log-info">Debug console ready</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <p class="text-muted">
                <small>
                    Laravel QZ Tray Package • v3.0.3 • QZ v2.2.6 Compatible •
                    <a href="https://github.com/bitdreamit/laravel-qz-tray" class="text-decoration-none">
                        <i class="fab fa-github mr-1"></i> GitHub
                    </a> •
                    <a href="https://qz.io" class="text-decoration-none">
                        <i class="fas fa-external-link-alt mr-1"></i> QZ Tray
                    </a> •
                    <a href="javascript:void(0)" class="text-decoration-none" onclick="showAbout()">
                        <i class="fas fa-info-circle mr-1"></i> About
                    </a>
                </small>
            </p>
        </div>
    </div>
</div>

<!-- About Modal -->
<div class="modal fade" id="aboutModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle mr-2"></i> About QZ Tray Test
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <h5>Laravel QZ Tray Integration</h5>
                <p>Version: 3.0.3</p>
                <p>Compatible with QZ Tray v2.2.6</p>
                <hr>
                <h6>Requirements:</h6>
                <ul>
                    <li>QZ Tray desktop application installed and running</li>
                    <li>Laravel backend with /qz/certificate and /qz/sign endpoints</li>
                    <li>WebSocket connection to QZ Tray (port 8181 by default)</li>
                </ul>
                <h6>Features:</h6>
                <ul>
                    <li>Auto-detection of QZ Tray connection</li>
                    <li>Printer discovery and selection</li>
                    <li>Multiple print types (HTML, PDF, ZPL, RAW)</li>
                    <li>Connection status monitoring</li>
                    <li>Real-time event logging</li>
                    <li>Certificate and signature testing</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 4.6 JS -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- QZ Tray JS (v2.2.6) -->
<script src="{{ asset('vendor/qz-tray/qz-tray.js') }}"></script>

<!-- SmartPrint Library (v3.0.3 - QZ v2.2.6 Compatible) -->
<script>
    /**
     * Laravel QZ Tray - Smart Print Enhanced (Compatible with QZ v2.2.6)
     * Version: 3.0.3
     */
    (function() {
        'use strict';

        const CONFIG = {
            ENDPOINT: '/qz',
            VERSION: '3.0.3',
            PRINT_TIMEOUT: 30000,
            CONNECT_TIMEOUT: 10000,
            STORAGE_KEYS: {
                CERTIFICATE: 'qz_certificate_v3',
                PRINTERS: 'qz_printers_v3',
                SETTINGS: 'qz_settings_v3',
            },
        };

        const state = {
            connected: false,
            printers: [],
            settings: {
                autoConnect: true,
                cachePrinters: true,
                secureConnection: false,
                defaultPort: 8181
            },
            printQueue: [],
            currentPrinter: null,
            eventListeners: new Map(),
            qzInitialized: false,
            connectionHost: 'localhost',
            connectionPort: 8181,
            connectionSecure: false
        };

        function log(...args) {
            console.log('[SmartPrint]', ...args);
        }

        function emit(event, data = {}) {
            log(`Event: ${event}`, data);

            const fullEvent = `smartprint:${event}`;
            const eventObj = new CustomEvent(fullEvent, { detail: data });
            document.dispatchEvent(eventObj);

            if (state.eventListeners.has(event)) {
                state.eventListeners.get(event).forEach(callback => {
                    try {
                        callback(data);
                    } catch (e) {
                        console.error('Event listener error:', e);
                    }
                });
            }
        }

        async function waitForQz() {
            return new Promise((resolve) => {
                if (window.qz && window.qz.version) {
                    log('QZ Tray library loaded:', window.qz.version);
                    resolve();
                    return;
                }

                const check = setInterval(() => {
                    if (window.qz && window.qz.version) {
                        clearInterval(check);
                        log('QZ Tray library loaded');
                        resolve();
                    }
                }, 100);
            });
        }

        function setupSecurity() {
            log('Setting up QZ Tray security...');

            if (!window.qz || !window.qz.security) {
                log('QZ Tray security API not available');
                return;
            }

            qz.security.setCertificatePromise(function(resolve, reject) {
                const cached = sessionStorage.getItem(CONFIG.STORAGE_KEYS.CERTIFICATE);

                if (cached) {
                    log('Using cached certificate');
                    resolve(cached);
                    return;
                }

                log('Fetching certificate from server');
                fetch(`${CONFIG.ENDPOINT}/certificate`)
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Certificate fetch failed: ' + response.status);
                        }
                        return response.text();
                    })
                    .then(function(certificate) {
                        if (certificate) {
                            sessionStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE, certificate);
                            resolve(certificate);
                        } else {
                            throw new Error('Invalid certificate');
                        }
                    })
                    .catch(function(err) {
                        console.error('Certificate error:', err);
                        reject(err);
                    });
            });

            qz.security.setSignaturePromise(function(toSign) {
                log('Requesting signature...');

                const headers = {
                    'Content-Type': 'application/json'
                };

                const csrfToken = document.querySelector('meta[name="csrf-token"]');
                if (csrfToken) {
                    headers['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
                }

                return fetch(`${CONFIG.ENDPOINT}/sign`, {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        data: toSign,
                        timestamp: Date.now()
                    })
                })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Signature failed: ' + response.status);
                        }
                        return response.text();
                    })
                    .catch(function(error) {
                        console.error('Signature error:', error);
                        throw error;
                    });
            });

            state.qzInitialized = true;
            log('Security setup complete');
        }

        function isConnected() {
            try {
                return state.connected && qz && qz.websocket && qz.websocket.isActive();
            } catch (e) {
                return false;
            }
        }

        async function connect(host = state.connectionHost, port = state.connectionPort, secure = state.connectionSecure) {
            if (isConnected()) {
                emit('connected', { host, port, secure });
                return true;
            }

            log(`Connecting to ${host}:${port} (secure: ${secure})...`);
            emit('connecting', { host, port, secure });

            try {
                await waitForQz();

                if (!window.qz || !window.qz.websocket) {
                    throw new Error('QZ Tray API not available');
                }

                if (!state.qzInitialized) {
                    setupSecurity();
                }

                // Clear any existing connection
                try {
                    if (qz.websocket.isActive()) {
                        await qz.websocket.disconnect();
                    }
                    await new Promise(resolve => setTimeout(resolve, 300));
                } catch (e) {
                    // Ignore disconnect errors
                }

                // Try different connection methods for QZ v2.2.6
                let connected = false;
                let error = null;

                // Method 1: With all parameters
                try {
                    await qz.websocket.connect(host, port, secure, 3, 1000);
                    connected = true;
                } catch (err1) {
                    error = err1;
                    log('Method 1 failed:', err1.message);

                    // Method 2: Without retries
                    try {
                        await qz.websocket.connect(host, port, secure);
                        connected = true;
                    } catch (err2) {
                        error = err2;
                        log('Method 2 failed:', err2.message);

                        // Method 3: Just host and port
                        try {
                            await qz.websocket.connect(host, port);
                            connected = true;
                        } catch (err3) {
                            error = err3;
                            log('Method 3 failed:', err3.message);

                            // Method 4: Just host
                            try {
                                await qz.websocket.connect(host);
                                connected = true;
                            } catch (err4) {
                                error = err4;
                                log('Method 4 failed:', err4.message);

                                // Method 5: No parameters
                                try {
                                    await qz.websocket.connect();
                                    connected = true;
                                } catch (err5) {
                                    error = err5;
                                    log('Method 5 failed:', err5.message);
                                }
                            }
                        }
                    }
                }

                if (!connected) {
                    throw error || new Error('All connection methods failed');
                }

                // Wait for connection to stabilize
                await new Promise(resolve => setTimeout(resolve, 500));

                if (qz.websocket.isActive()) {
                    state.connected = true;
                    emit('connected', { host, port, secure });
                    log(`✅ Connected to ${host}:${port}`);
                    return true;
                } else {
                    throw new Error('Connection established but not active');
                }

            } catch (error) {
                console.error('Connection error:', error);
                state.connected = false;
                emit('connection-failed', {
                    error: error.message,
                    host,
                    port,
                    secure
                });
                throw error;
            }
        }

        async function disconnect() {
            try {
                if (qz.websocket && qz.websocket.isActive()) {
                    await qz.websocket.disconnect();
                }
            } catch (error) {
                // Ignore disconnect errors
            }

            state.connected = false;
            emit('disconnected');
            log('Disconnected from QZ Tray');
            return true;
        }

        async function findPrinters(search = null) {
            log(`Finding printers${search ? ` matching: ${search}` : ''}...`);
            emit('finding-printers', { search });

            try {
                if (!isConnected()) {
                    await connect();
                }

                let printers;
                if (search) {
                    printers = await qz.printers.find(search);
                } else {
                    printers = await qz.printers.find();
                }

                if (printers && printers.length > 0) {
                    state.printers = printers;

                    if (state.settings.cachePrinters) {
                        localStorage.setItem(CONFIG.STORAGE_KEYS.PRINTERS, JSON.stringify({
                            printers: printers,
                            timestamp: Date.now(),
                        }));
                    }

                    emit('printers-found', {
                        printers,
                        count: printers.length
                    });
                    log(`✅ Found ${printers.length} printer(s)`);
                    return printers;
                }

                log('No printers found');
                emit('printers-not-found');
                return [];

            } catch (error) {
                console.error('Failed to find printers:', error);
                emit('printers-error', { error: error.message });

                // Try cached printers
                try {
                    const cached = localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTERS);
                    if (cached) {
                        const data = JSON.parse(cached);
                        log('Using cached printers');
                        return data.printers || [];
                    }
                } catch (e) {
                    // Ignore cache errors
                }

                return [];
            }
        }

        async function findDefaultPrinter() {
            log('Finding default printer...');
            emit('finding-default-printer');

            try {
                if (!isConnected()) {
                    await connect();
                }

                const defaultPrinter = await qz.printers.getDefault();

                if (defaultPrinter) {
                    log(`✅ Default printer: ${defaultPrinter}`);
                    emit('default-printer-found', { printer: defaultPrinter });
                    return defaultPrinter;
                }

                log('No default printer found');
                emit('default-printer-not-found');
                return null;

            } catch (error) {
                console.error('Failed to find default printer:', error);
                emit('default-printer-error', { error: error.message });
                return null;
            }
        }

        async function setPrinter(printerName) {
            log(`Setting printer to: ${printerName}`);
            emit('setting-printer', { printer: printerName });

            try {
                const printers = await findPrinters(printerName);
                const printer = printers.find(p => p === printerName);

                if (!printer) {
                    throw new Error(`Printer "${printerName}" not found`);
                }

                state.currentPrinter = printerName;
                localStorage.setItem('qz_selected_printer', printerName);

                emit('printer-set', {
                    printer: printerName,
                    previous: state.currentPrinter
                });

                log(`✅ Printer set to: ${printerName}`);
                return { printer: printerName };

            } catch (error) {
                console.error('Failed to set printer:', error);
                emit('printer-set-error', { error: error.message });
                return null;
            }
        }

        async function smartPrint(content, options = {}) {
            const jobId = 'job_' + Date.now();
            const startTime = Date.now();

            let printer = options.printer;
            if (!printer) {
                if (state.currentPrinter) {
                    printer = state.currentPrinter;
                } else {
                    const defaultPrinter = await findDefaultPrinter();
                    printer = defaultPrinter || '';
                }
            }

            const job = {
                id: jobId,
                content: content,
                printer: printer,
                options: {
                    type: options.type || 'html',
                    copies: parseInt(options.copies) || 1,
                    color: options.color || false,
                    ...options
                },
                status: 'queued',
                startTime: startTime,
            };

            log(`Queuing print job to ${printer}...`);
            emit('job-queued', { job });

            try {
                if (!isConnected()) {
                    await connect();
                }

                const printers = await findPrinters(printer);
                if (printers.length === 0) {
                    throw new Error(`Printer "${printer}" not found`);
                }

                const config = qz.configs.create(printer, {
                    copies: job.options.copies,
                    colorType: job.options.color ? 'color' : 'monochrome',
                });

                let printData;
                if (job.options.type === 'zpl' || job.options.type === 'raw') {
                    printData = [{
                        type: 'raw',
                        data: content,
                        options: {
                            language: job.options.type === 'zpl' ? 'ZPL' : 'ESCPOS'
                        }
                    }];
                } else if (job.options.type === 'pdf') {
                    printData = [{
                        type: 'pdf',
                        format: 'file',
                        data: content
                    }];
                } else {
                    printData = [{
                        type: 'html',
                        format: 'html',
                        data: content
                    }];
                }

                await qz.print(config, printData);

                job.status = 'completed';
                job.duration = Date.now() - startTime;

                emit('job-completed', { job });
                log(`✅ Printed successfully in ${job.duration}ms`);
                return jobId;

            } catch (error) {
                job.status = 'failed';
                job.error = error.message;
                job.duration = Date.now() - startTime;

                emit('job-failed', { job, error: error.message });
                console.error(`Print failed: ${error.message}`);
                throw error;
            }
        }

        function getStatus() {
            return {
                connected: isConnected(),
                printers: state.printers.length,
                currentPrinter: state.currentPrinter,
                connectionHost: state.connectionHost,
                connectionPort: state.connectionPort,
                connectionSecure: state.connectionSecure,
                version: CONFIG.VERSION,
                qzVersion: window.qz ? window.qz.version : 'unknown',
            };
        }

        async function testConnection() {
            log('Testing connection...');
            emit('testing-connection');

            const start = Date.now();

            try {
                const connected = await connect();

                if (connected) {
                    const printers = await findPrinters();
                    const duration = Date.now() - start;

                    const result = {
                        success: true,
                        duration,
                        printers: printers.length,
                        qzConnected: isConnected(),
                        version: CONFIG.VERSION
                    };

                    emit('connection-test-success', result);
                    return result;
                } else {
                    throw new Error('Could not establish connection');
                }
            } catch (error) {
                const result = {
                    success: false,
                    error: error.message,
                    duration: Date.now() - start,
                    qzConnected: false
                };

                emit('connection-test-failed', result);
                return result;
            }
        }

        async function initialize() {
            try {
                log('Initializing Smart Print v' + CONFIG.VERSION);

                await waitForQz();

                if (!window.qz) {
                    throw new Error('QZ Tray library not loaded');
                }

                setupSecurity();

                const savedSettings = localStorage.getItem(CONFIG.STORAGE_KEYS.SETTINGS);
                if (savedSettings) {
                    state.settings = { ...state.settings, ...JSON.parse(savedSettings) };
                }

                const savedPrinter = localStorage.getItem('qz_selected_printer');
                if (savedPrinter) {
                    state.currentPrinter = savedPrinter;
                }

                const cachedPrinters = localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTERS);
                if (cachedPrinters) {
                    try {
                        const data = JSON.parse(cachedPrinters);
                        state.printers = data.printers || [];
                    } catch (e) {
                        // Ignore cache errors
                    }
                }

                emit('ready', {
                    version: CONFIG.VERSION,
                    qzVersion: window.qz.version,
                    settings: state.settings,
                    connected: isConnected()
                });

                log('✅ Smart Print initialized');

                if (state.settings.autoConnect) {
                    setTimeout(async () => {
                        try {
                            await connect();
                            await findPrinters();
                            await findDefaultPrinter();
                        } catch (error) {
                            log('Auto-connect failed:', error.message);
                        }
                    }, 1000);
                }

            } catch (error) {
                console.error('Initialization failed:', error);
                emit('init-failed', { error: error.message });
            }
        }

        const SmartPrintAPI = {
            connect: connect,
            disconnect: disconnect,
            isConnected: isConnected,
            testConnection: testConnection,
            findPrinters: findPrinters,
            findDefaultPrinter: findDefaultPrinter,
            setPrinter: setPrinter,
            getCurrentPrinter: () => state.currentPrinter,
            print: smartPrint,
            printRaw: function(data, options = {}) {
                return smartPrint(data, { ...options, type: 'raw' });
            },
            printZPL: function(zplData, options = {}) {
                return smartPrint(zplData, { ...options, type: 'zpl' });
            },
            printPDF: function(pdfUrl, options = {}) {
                return smartPrint(pdfUrl, { ...options, type: 'pdf' });
            },
            printHTML: function(html, options = {}) {
                return smartPrint(html, { ...options, type: 'html' });
            },
            getStatus: getStatus,
            getPrinters: () => state.printers,
            updateSettings: function(newSettings) {
                state.settings = { ...state.settings, ...newSettings };
                localStorage.setItem(CONFIG.STORAGE_KEYS.SETTINGS, JSON.stringify(state.settings));
                emit('settings-changed', { settings: state.settings });
                return state.settings;
            },
            getSettings: () => ({ ...state.settings }),
            updateConnection: function(host, port = 8181, secure = false) {
                state.connectionHost = host;
                state.connectionPort = port;
                state.connectionSecure = secure;
                emit('connection-changed', { host, port, secure });
            },
            getConnection: () => ({
                host: state.connectionHost,
                port: state.connectionPort,
                secure: state.connectionSecure
            }),
            on: function(event, callback) {
                if (!state.eventListeners.has(event)) {
                    state.eventListeners.set(event, []);
                }
                state.eventListeners.get(event).push(callback);
                return this;
            },
            off: function(event, callback) {
                if (state.eventListeners.has(event)) {
                    const listeners = state.eventListeners.get(event);
                    const index = listeners.indexOf(callback);
                    if (index > -1) {
                        listeners.splice(index, 1);
                    }
                }
                return this;
            },
            clearCache: function() {
                Object.values(CONFIG.STORAGE_KEYS).forEach(key => {
                    localStorage.removeItem(key);
                    sessionStorage.removeItem(key);
                });

                Object.keys(localStorage).forEach(key => {
                    if (key.startsWith('qz_')) {
                        localStorage.removeItem(key);
                    }
                });

                state.printers = [];
                state.connected = false;
                state.currentPrinter = null;

                emit('cache-cleared');
            },
            version: CONFIG.VERSION
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initialize);
        } else {
            initialize();
        }

        window.SmartPrint = SmartPrintAPI;
        window.smartPrint = smartPrint;

    })();

    // Demo Interface Code
    let demoState = {
        connected: false,
        printers: [],
        selectedPrinter: null,
        eventLog: [],
        connectionProgress: 0
    };

    document.addEventListener('DOMContentLoaded', function() {
        initializeDemo();
    });

    function initializeDemo() {
        logEvent('System initialized', 'info');
        updateSystemInfo();

        setTimeout(() => {
            if (window.SmartPrint) {
                logEvent('✅ SmartPrint v' + window.SmartPrint.version + ' loaded', 'success');
                document.getElementById('packageVersion').textContent = window.SmartPrint.version;

                const status = SmartPrint.getStatus();
                updateDemoStatus(status);

                setupSmartPrintListeners();
            } else {
                logEvent('❌ SmartPrint library not loaded', 'error');
            }
        }, 100);
    }

    function setupSmartPrintListeners() {
        if (!window.SmartPrint) return;

        SmartPrint.on('connecting', (data) => {
            logEvent(`Connecting to ${data.host}:${data.port}...`, 'info');
            updateStatus(false, 'Connecting...');
            updateProgress(30);
        });

        SmartPrint.on('connected', (data) => {
            logEvent(`✅ Connected to ${data.host}:${data.port}`, 'success');
            demoState.connected = true;
            updateStatus(true, `${data.host}:${data.port}`);
            updateProgress(100);
            updateConnectionInfo(`${data.host}:${data.port}`);
        });

        SmartPrint.on('connection-failed', (data) => {
            logEvent(`❌ Connection failed: ${data.error}`, 'error');
            demoState.connected = false;
            updateStatus(false, data.error);
            updateProgress(0);
        });

        SmartPrint.on('disconnected', () => {
            logEvent('Disconnected from QZ Tray', 'info');
            demoState.connected = false;
            updateStatus(false, 'Disconnected');
            updateConnectionInfo('Not connected');
        });

        SmartPrint.on('printers-found', (data) => {
            demoState.printers = data.printers;
            logEvent(`✅ Found ${data.count} printer(s)`, 'success');
            updatePrinterList();
        });

        SmartPrint.on('printer-set', (data) => {
            demoState.selectedPrinter = data.printer;
            logEvent(`✅ Printer set to: ${data.printer}`, 'success');
            updatePrinterList();
        });

        SmartPrint.on('default-printer-found', (data) => {
            if (data.printer) {
                demoState.selectedPrinter = data.printer;
                logEvent(`✅ Default printer: ${data.printer}`, 'success');
                updatePrinterList();
            }
        });

        SmartPrint.on('job-queued', (data) => {
            logEvent(`Job queued for ${data.job.printer}`, 'info');
            updateQueueCount();
        });

        SmartPrint.on('job-completed', (data) => {
            logEvent(`✅ Print completed in ${data.job.duration}ms`, 'success');
            updateCompletedCount();
        });

        SmartPrint.on('job-failed', (data) => {
            logEvent(`❌ Print failed: ${data.error}`, 'error');
        });
    }

    async function connectQz() {
        const host = document.getElementById('connectionHost').value;
        const port = parseInt(document.getElementById('connectionPort').value);
        const secure = document.getElementById('connectionSecure').checked;

        if (!window.SmartPrint) {
            logEvent('❌ SmartPrint not loaded', 'error');
            return;
        }

        try {
            SmartPrint.updateConnection(host, port, secure);
            await SmartPrint.connect();
        } catch (error) {
            logEvent(`Connection error: ${error.message}`, 'error');
        }
    }

    async function disconnectQz() {
        if (!window.SmartPrint) return;

        try {
            await SmartPrint.disconnect();
        } catch (error) {
            logEvent(`Disconnect error: ${error.message}`, 'error');
        }
    }

    async function testConnection() {
        if (!window.SmartPrint) {
            logEvent('❌ SmartPrint not loaded', 'error');
            return;
        }

        logEvent('Testing connection...', 'info');
        const result = await SmartPrint.testConnection();

        if (result.success) {
            logEvent(`✅ Connection test passed! (${result.duration}ms, ${result.printers} printers)`, 'success');
        } else {
            logEvent(`❌ Connection test failed: ${result.error}`, 'error');
        }
    }

    async function findAllPrinters() {
        if (!window.SmartPrint) {
            logEvent('❌ SmartPrint not loaded', 'error');
            return;
        }

        try {
            const printers = await SmartPrint.findPrinters();
            return printers;
        } catch (error) {
            logEvent(`Failed to find printers: ${error.message}`, 'error');
            return [];
        }
    }

    async function findDefaultPrinter() {
        if (!window.SmartPrint) {
            logEvent('❌ SmartPrint not loaded', 'error');
            return;
        }

        try {
            const printer = await SmartPrint.findDefaultPrinter();
            if (printer) {
                SmartPrint.setPrinter(printer);
            }
            return printer;
        } catch (error) {
            logEvent(`Failed to find default printer: ${error.message}`, 'error');
            return null;
        }
    }

    function updatePrinterList() {
        const container = document.getElementById('printerList');
        const countElement = document.getElementById('printerCount');
        const currentPrinterDisplay = document.getElementById('currentPrinterDisplay');
        const setPrinterBtn = document.getElementById('setPrinterBtn');
        const detectedPrinters = document.getElementById('detectedPrinters');

        countElement.textContent = `${demoState.printers.length} printer(s)`;
        detectedPrinters.textContent = demoState.printers.length;

        if (demoState.printers.length === 0) {
            container.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="fas fa-print fa-2x mb-3"></i>
                <p>No printers found</p>
            </div>
        `;
            return;
        }

        let html = '';
        demoState.printers.forEach((printer) => {
            const printerName = printer;
            const isSelected = printerName === demoState.selectedPrinter;

            html += `
            <div class="printer-item ${isSelected ? 'active' : ''}"
                 onclick="selectPrinter('${printerName.replace(/'/g, "\\'")}')"
                 data-printer="${printerName}">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-print ${isSelected ? 'text-success' : 'text-primary'}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="font-weight-bold">${printerName}</div>
                        <small class="text-muted">Click to select</small>
                    </div>
                    ${isSelected ? '<i class="fas fa-check text-success"></i>' : ''}
                </div>
            </div>
        `;
        });

        container.innerHTML = html;

        if (demoState.selectedPrinter) {
            currentPrinterDisplay.className = 'alert alert-success';
            currentPrinterDisplay.innerHTML = `<i class="fas fa-print mr-2"></i> ${demoState.selectedPrinter}`;
            setPrinterBtn.disabled = false;
        } else {
            currentPrinterDisplay.className = 'alert alert-secondary';
            currentPrinterDisplay.innerHTML = '<i class="fas fa-print mr-2"></i> No printer selected';
            setPrinterBtn.disabled = true;
        }
    }

    function selectPrinter(printerName) {
        demoState.selectedPrinter = printerName;
        updatePrinterList();
        logEvent(`Selected printer: ${printerName}`, 'info');
    }

    async function setSelectedPrinter() {
        if (!window.SmartPrint || !demoState.selectedPrinter) return;

        try {
            await SmartPrint.setPrinter(demoState.selectedPrinter);
        } catch (error) {
            logEvent(`Failed to set printer: ${error.message}`, 'error');
        }
    }

    async function testPrint() {
        if (!window.SmartPrint || !demoState.selectedPrinter) {
            logEvent('⚠️ Please select a printer first', 'warning');
            return;
        }

        logEvent(`Testing print to ${demoState.selectedPrinter}...`, 'info');

        try {
            const htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; padding: 30px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .content { line-height: 1.6; }
                    .footer { margin-top: 40px; text-align: center; color: #666; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1 style="color: #007bff;">✅ QZ Tray Test Print</h1>
                    <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
                    <p><strong>Time:</strong> ${new Date().toLocaleTimeString()}</p>
                </div>
                <div class="content">
                    <p>This is a test print from the Laravel QZ Tray package.</p>
                    <p>If you can read this, printing is working correctly!</p>
                    <ul>
                        <li>Printer: ${demoState.selectedPrinter}</li>
                        <li>Connection: ${demoState.connected ? 'Connected' : 'Disconnected'}</li>
                        <li>Printers Found: ${demoState.printers.length}</li>
                    </ul>
                </div>
                <div class="footer">
                    <hr>
                    <p>Generated by Laravel QZ Tray Package v3.0.3</p>
                </div>
            </body>
            </html>
        `;

            await SmartPrint.printHTML(htmlContent, {
                printer: demoState.selectedPrinter,
                copies: 1,
                color: true
            });

        } catch (error) {
            logEvent(`❌ Print failed: ${error.message}`, 'error');
        }
    }

    async function testZplPrint() {
        if (!window.SmartPrint || !demoState.selectedPrinter) {
            logEvent('⚠️ Please select a printer first', 'warning');
            return;
        }

        logEvent(`Testing ZPL print to ${demoState.selectedPrinter}...`, 'info');

        try {
            const zpl = `^XA
            ^FO50,50^A0N,50,50^FDLaravel QZ Tray Test^FS
            ^FO50,120^A0N,30,30^FDTest Label^FS
            ^FO50,160^A0N,25,25^FDDate: ${new Date().toLocaleDateString()}^FS
            ^FO50,190^A0N,25,25^FDTime: ${new Date().toLocaleTimeString()}^FS
            ^FO50,230^GB700,3,3^FS
            ^FO50,250^A0N,20,20^FD✅ Test Successful^FS
            ^XZ`;

            await SmartPrint.printZPL(zpl, {
                printer: demoState.selectedPrinter
            });

        } catch (error) {
            logEvent(`❌ ZPL print failed: ${error.message}`, 'error');
        }
    }

    async function testHtmlPrint() {
        if (!window.SmartPrint || !demoState.selectedPrinter) {
            logEvent('⚠️ Please select a printer first', 'warning');
            return;
        }

        logEvent(`Testing HTML print to ${demoState.selectedPrinter}...`, 'info');

        try {
            const html = document.getElementById('testData').value || 'Test HTML Content';

            await SmartPrint.printHTML(html, {
                printer: demoState.selectedPrinter,
                copies: 1
            });

        } catch (error) {
            logEvent(`❌ HTML print failed: ${error.message}`, 'error');
        }
    }

    async function testRawPrint() {
        if (!window.SmartPrint || !demoState.selectedPrinter) {
            logEvent('⚠️ Please select a printer first', 'warning');
            return;
        }

        logEvent(`Testing RAW print to ${demoState.selectedPrinter}...`, 'info');

        try {
            const rawData = 'RAW Print Test\n' +
                '==============\n' +
                'Time: ' + new Date().toLocaleString() + '\n' +
                'Printer: ' + demoState.selectedPrinter + '\n' +
                'Status: Testing RAW printing\n';

            await SmartPrint.printRaw(rawData, {
                printer: demoState.selectedPrinter
            });

        } catch (error) {
            logEvent(`❌ RAW print failed: ${error.message}`, 'error');
        }
    }

    async function customPrint() {
        if (!window.SmartPrint || !demoState.selectedPrinter) {
            logEvent('⚠️ Please select a printer first', 'warning');
            return;
        }

        const type = document.getElementById('printType').value;
        const copies = parseInt(document.getElementById('printCopies').value) || 1;
        const data = document.getElementById('testData').value;

        logEvent(`Custom ${type} print (${copies} copies) to ${demoState.selectedPrinter}...`, 'info');

        try {
            if (type === 'zpl') {
                await SmartPrint.printZPL(data, {
                    printer: demoState.selectedPrinter,
                    copies: copies
                });
            } else if (type === 'raw') {
                await SmartPrint.printRaw(data, {
                    printer: demoState.selectedPrinter,
                    copies: copies
                });
            } else if (type === 'pdf') {
                await SmartPrint.printPDF(data, {
                    printer: demoState.selectedPrinter,
                    copies: copies
                });
            } else {
                await SmartPrint.printHTML(data, {
                    printer: demoState.selectedPrinter,
                    copies: copies
                });
            }

        } catch (error) {
            logEvent(`❌ Custom print failed: ${error.message}`, 'error');
        }
    }

    function updateStatus(connected, message = '') {
        demoState.connected = connected;
        const banner = document.getElementById('statusBanner');
        const appStatus = document.getElementById('appStatus');
        const connectBtn = document.getElementById('connectBtn');
        const disconnectBtn = document.getElementById('disconnectBtn');
        const progressBar = document.getElementById('connectionProgress');

        if (connected) {
            banner.className = 'connection-status connected-bg';
            banner.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' +
                `<span id="statusText">Connected to QZ Tray ${message ? '(' + message + ')' : ''}</span>`;
            appStatus.className = 'badge badge-success';
            appStatus.innerHTML = '<span class="status-indicator status-connected"></span> Connected';
            connectBtn.disabled = true;
            disconnectBtn.disabled = false;
            progressBar.style.width = '100%';
            progressBar.className = 'progress-bar bg-success';
        } else {
            banner.className = 'connection-status disconnected-bg';
            banner.innerHTML = '<i class="fas fa-times-circle mr-2"></i>' +
                `<span id="statusText">Disconnected ${message ? '(' + message + ')' : ''}</span>`;
            appStatus.className = 'badge badge-danger';
            appStatus.innerHTML = '<span class="status-indicator status-disconnected"></span> Disconnected';
            connectBtn.disabled = false;
            disconnectBtn.disabled = true;
            progressBar.style.width = '0%';
            progressBar.className = 'progress-bar';
        }
    }

    function updateProgress(percent) {
        const progressBar = document.getElementById('connectionProgress');
        progressBar.style.width = percent + '%';
        demoState.connectionProgress = percent;
    }

    function updateDemoStatus(status) {
        demoState.connected = status.connected;

        if (status.connected) {
            updateStatus(true, `${status.connectionHost}:${status.connectionPort}`);
        } else {
            updateStatus(false);
        }

        document.getElementById('qzVersion').textContent = status.qzVersion;
        document.getElementById('wsStatus').className = status.connected ? 'badge badge-success' : 'badge badge-danger';
        document.getElementById('wsStatus').textContent = status.connected ? 'Connected' : 'Disconnected';
        document.getElementById('lastActivity').textContent = new Date().toLocaleTimeString();
    }

    function updateConnectionInfo(info) {
        document.getElementById('connectionInfo').textContent = info;
    }

    function updateQueueCount() {
        const count = parseInt(document.getElementById('queueCount').textContent) + 1;
        document.getElementById('queueCount').textContent = count;
    }

    function updateCompletedCount() {
        const count = parseInt(document.getElementById('completedCount').textContent) + 1;
        document.getElementById('completedCount').textContent = count;

        // Decrease queue count
        const queueCount = parseInt(document.getElementById('queueCount').textContent);
        if (queueCount > 0) {
            document.getElementById('queueCount').textContent = queueCount - 1;
        }
    }

    function logEvent(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const entry = { timestamp, message, type };
        demoState.eventLog.push(entry);

        const logContainer = document.getElementById('eventLog');
        const entryDiv = document.createElement('div');
        entryDiv.className = 'log-entry';
        entryDiv.innerHTML = `
        <span class="log-timestamp">[${timestamp}]</span>
        <span class="log-${type}">${message}</span>
    `;
        logContainer.appendChild(entryDiv);
        logContainer.scrollTop = logContainer.scrollHeight;

        console.log(`[${type.toUpperCase()}] ${message}`);
    }

    function refreshPrinters() {
        findAllPrinters();
        logEvent('Refreshing printer list...', 'info');
    }

    function searchPrinters() {
        const searchTerm = document.getElementById('printerSearch').value;
        if (!searchTerm) {
            findAllPrinters();
            return;
        }

        logEvent(`Searching for printers: "${searchTerm}"`, 'info');

        const filtered = demoState.printers.filter(printer => {
            return printer.toLowerCase().includes(searchTerm.toLowerCase());
        });

        const container = document.getElementById('printerList');
        const countElement = document.getElementById('printerCount');

        countElement.textContent = `${filtered.length} printer(s) found`;

        if (filtered.length === 0) {
            container.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="fas fa-search fa-2x mb-3"></i>
                <p>No printers match "${searchTerm}"</p>
            </div>
        `;
            return;
        }

        let html = '';
        filtered.forEach((printer) => {
            const printerName = printer;
            const isSelected = printerName === demoState.selectedPrinter;

            html += `
            <div class="printer-item ${isSelected ? 'active' : ''}"
                 onclick="selectPrinter('${printerName.replace(/'/g, "\\'")}')">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-print ${isSelected ? 'text-success' : 'text-primary'}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="font-weight-bold">${printerName}</div>
                        <small class="text-muted">Click to select</small>
                    </div>
                    ${isSelected ? '<i class="fas fa-check text-success"></i>' : ''}
                </div>
            </div>
        `;
        });

        container.innerHTML = html;
    }

    function clearQueue() {
        document.getElementById('queueCount').textContent = '0';
        document.getElementById('activeCount').textContent = '0';
        logEvent('Print queue cleared', 'info');
    }

    function updateSystemInfo() {
        document.getElementById('lastActivity').textContent = new Date().toLocaleTimeString();
    }

    function refreshSystemInfo() {
        if (window.SmartPrint) {
            const status = SmartPrint.getStatus();
            updateDemoStatus(status);
        }
        logEvent('System info refreshed', 'info');
    }

    function testCertificate() {
        logEvent('Testing certificate endpoint...', 'info');

        fetch('/qz/certificate')
            .then(response => {
                if (response.ok) {
                    return response.text().then(text => {
                        document.getElementById('certStatus').className = 'badge badge-success';
                        document.getElementById('certStatus').textContent = 'Valid';
                        logEvent(`✅ Certificate working (${text.length} chars)`, 'success');
                    });
                } else {
                    throw new Error(`HTTP ${response.status}`);
                }
            })
            .catch(error => {
                document.getElementById('certStatus').className = 'badge badge-danger';
                document.getElementById('certStatus').textContent = 'Invalid';
                logEvent(`❌ Certificate error: ${error.message}`, 'error');
            });
    }

    function testSignature() {
        logEvent('Testing signature endpoint...', 'info');

        fetch('/qz/sign', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ data: 'test_' + Date.now() })
        })
            .then(response => {
                if (response.ok) {
                    return response.text().then(text => {
                        logEvent(`✅ Signature working (${text.length} chars)`, 'success');
                    });
                } else {
                    throw new Error(`HTTP ${response.status}`);
                }
            })
            .catch(error => {
                logEvent(`❌ Signature error: ${error.message}`, 'error');
            });
    }

    function testEndpoints() {
        testCertificate();
        testSignature();
    }

    function testSimpleConnect() {
        logEvent('Testing simple connection...', 'info');

        if (!window.qz || !window.qz.websocket) {
            logEvent('❌ QZ Tray not loaded', 'error');
            return;
        }

        const methods = [
            { name: 'No parameters', func: () => qz.websocket.connect() },
            { name: 'localhost only', func: () => qz.websocket.connect('localhost') },
            { name: 'localhost:8181', func: () => qz.websocket.connect('localhost', 8181) },
            { name: 'localhost:8181 (unsecure)', func: () => qz.websocket.connect('localhost', 8181, false) },
            { name: 'localhost:8182 (secure)', func: () => qz.websocket.connect('localhost', 8182, true) }
        ];

        methods.forEach(method => {
            logEvent(`Trying: ${method.name}`, 'info');

            try {
                method.func().then(() => {
                    if (qz.websocket.isActive()) {
                        logEvent(`✅ ${method.name}: SUCCESS!`, 'success');
                        qz.websocket.disconnect();
                    }
                }).catch(err => {
                    logEvent(`❌ ${method.name}: ${err.message}`, 'error');
                });
            } catch (err) {
                logEvent(`❌ ${method.name}: ${err.message}`, 'error');
            }
        });
    }

    function testConnectionMethods() {
        logEvent('Testing connection methods...', 'info');

        const host = document.getElementById('connectionHost').value;
        const port = parseInt(document.getElementById('connectionPort').value);
        const secure = document.getElementById('connectionSecure').checked;

        const methods = [
            { name: 'Method 1: All params', func: () => qz.websocket.connect(host, port, secure, 3, 1000) },
            { name: 'Method 2: Without retries', func: () => qz.websocket.connect(host, port, secure) },
            { name: 'Method 3: Host & port', func: () => qz.websocket.connect(host, port) },
            { name: 'Method 4: Host only', func: () => qz.websocket.connect(host) },
            { name: 'Method 5: No params', func: () => qz.websocket.connect() }
        ];

        methods.forEach(method => {
            logEvent(`Testing: ${method.name}`, 'info');

            method.func().then(() => {
                if (qz.websocket.isActive()) {
                    logEvent(`✅ ${method.name}: Connected!`, 'success');
                    qz.websocket.disconnect();
                }
            }).catch(err => {
                logEvent(`❌ ${method.name}: ${err.message}`, 'error');
            });
        });
    }

    function debugQzApi() {
        logEvent('=== DEBUG QZ TRAY API ===', 'info');

        if (!window.qz) {
            logEvent('❌ window.qz is not defined', 'error');
            return;
        }

        logEvent(`QZ Tray version: ${window.qz.version}`, 'info');
        logEvent(`SmartPrint version: ${window.SmartPrint ? window.SmartPrint.version : 'Not loaded'}`, 'info');

        if (qz.websocket) {
            logEvent('✅ qz.websocket available', 'success');
            logEvent(`isActive(): ${qz.websocket.isActive()}`, 'info');

            const methods = Object.getOwnPropertyNames(qz.websocket)
                .filter(key => typeof qz.websocket[key] === 'function');
            logEvent(`WebSocket methods: ${methods.join(', ')}`, 'info');
        } else {
            logEvent('❌ qz.websocket not available', 'error');
        }

        logEvent('=== END DEBUG ===', 'info');
    }

    function forceDisconnect() {
        if (window.qz && window.qz.websocket) {
            try {
                qz.websocket.disconnect();
                logEvent('Force disconnected', 'warning');
            } catch (err) {
                logEvent(`Force disconnect error: ${err.message}`, 'error');
            }
        }
    }

    function clearEventLog() {
        demoState.eventLog = [];
        document.getElementById('eventLog').innerHTML = '';
        logEvent('Event log cleared', 'info');
    }

    function exportLogs() {
        const logs = demoState.eventLog.map(entry =>
            `[${entry.timestamp}] ${entry.message}`
        ).join('\n');

        const blob = new Blob([logs], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `qz-tray-logs-${new Date().toISOString().slice(0, 10)}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        logEvent('Logs exported', 'success');
    }

    function copyLogs() {
        const logs = demoState.eventLog.map(entry =>
            `[${entry.timestamp}] ${entry.message}`
        ).join('\n');

        navigator.clipboard.writeText(logs).then(function() {
            logEvent('Logs copied to clipboard', 'success');
        }, function() {
            logEvent('Failed to copy logs', 'error');
        });
    }

    function clearAllCache() {
        if (window.SmartPrint) {
            SmartPrint.clearCache();
        }

        localStorage.clear();
        sessionStorage.clear();

        demoState.printers = [];
        demoState.selectedPrinter = null;
        demoState.connected = false;

        updatePrinterList();
        updateStatus(false);

        logEvent('All cache cleared', 'success');
    }

    function showAbout() {
        $('#aboutModal').modal('show');
    }

    // Initialize Bootstrap tabs
    $(function() {
        $('#logTabs a').on('click', function(e) {
            e.preventDefault();
            $(this).tab('show');
        });
    });
</script>

</body>
</html>
