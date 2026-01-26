@extends('layouts.app')

@section('head')
    <style>
        /* ===============================
           Smart Print - Queue & Modal UI
           =============================== */

        /* Queue List */
        #sp-queue-list {
            list-style: none;
            padding-left: 0;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 6px;
            background: #f9f9f9;
            font-family: Arial, sans-serif;
        }

        #sp-queue-list li {
            margin-bottom: 6px;
            font-size: 14px;
        }

        #sp-queue-list li button {
            margin-left: 10px;
            padding: 2px 6px;
            font-size: 12px;
            border: 1px solid #888;
            border-radius: 4px;
            background-color: #eee;
            cursor: pointer;
        }

        #sp-queue-list li button:hover {
            background-color: #ddd;
        }

        /* Printer Modal */
        .sp-modal {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .sp-box {
            background: #fff;
            padding: 20px 30px;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            max-width: 400px;
            width: 90%;
            text-align: center;
            font-family: Arial, sans-serif;
        }

        .sp-box h3 {
            margin-bottom: 15px;
        }

        .sp-box button {
            display: inline-block;
            margin: 5px 5px;
            padding: 8px 14px;
            font-size: 14px;
            border: 1px solid #888;
            border-radius: 4px;
            background-color: #eee;
            cursor: pointer;
            min-width: 120px;
        }

        .sp-box button:hover {
            background-color: #ddd;
        }
    </style>
    <link rel="stylesheet" href="{{asset('vendor/qz-tray/css/smart-print.css')}}" />
@endsection

@section('content')
    <div class="container">
        <h2>Smart Print Full Demo</h2>

        <!-- Queue / Retry UI -->
        <div>
            <h4>Print Queue</h4>
            <ul id="sp-queue-list"></ul>
        </div>

        <!-- Print Buttons -->
        <div style="margin-top:20px;">
            <button
                data-smart-print
                data-type="pdf"
                data-url="{{ route('qz.test.pdf') }}">
                Print Invoice (PDF)
            </button>

            <button
                data-smart-print
                data-type="zpl"
                data-data="^XA^FO50,50^FDHello ZPL^FS^XZ">
                Print Label (ZPL)
            </button>

            <button
                data-smart-print
                data-type="pdf"
                data-url="{{ route('receipts.show', ['id' => 456]) }}"
                data-auto-print="true">
                Auto Print Receipt
            </button>
        </div>

        <hr>

        <!-- Printer Selection Instructions -->
        <div>
            <h4>Change Printer</h4>
            <p>Press <strong>Ctrl+Shift+P</strong> to open the printer modal and select a new printer.</p>
        </div>
    </div>
@endsection

@section('scripts')
    <!-- Required scripts -->
    <script type="text/javascript" src="{{asset('vendor/qz-tray/js/qz-tray.js')}}"></script>
    <script type="text/javascript" src="{{asset('vendor/qz-tray/js/sample/jsrsasign-all-min.js')}}"></script>
    <script type="text/javascript" src="{{asset('vendor/qz-tray/js/smart-print.js')}}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            SmartPrint.init();
        });
    </script>
@endsection
