<!DOCTYPE html>
<html>
<head>
    <title>Medicine Release Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .content { padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .footer { margin-top: 20px; font-size: 12px; color: #666; }
        .details { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .medicine-list { background-color: #fff; border: 1px solid #ddd; border-radius: 5px; margin: 15px 0; }
        .medicine-item { padding: 15px; border-bottom: 1px solid #eee; }
        .medicine-item:last-child { border-bottom: none; }
        .medicine-name { font-weight: bold; color: #2c3e50; }
        .medicine-details { margin-top: 5px; font-size: 14px; color: #555; }
        .frequency { margin-top: 5px; }
        .frequency-badge { 
            display: inline-block; 
            background-color: #e3f2fd; 
            color: #1976d2; 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-size: 12px; 
            margin-right: 5px; 
        }
        .external-medicine { 
            background-color: #fff3cd; 
            border-left: 4px solid #ffc107; 
            padding: 10px 15px; 
            margin: 10px 0; 
        }
        .prescription-info { background-color: #e8f5e8; padding: 15px; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Medicine Release Confirmation</h2>
        </div>
        
        <div class="content">
            <p>Dear {{ $patient->name }},</p>
            
            <p>Your prescribed medicines have been successfully dispensed from <strong>{{ $hospital->name }}</strong>. Please find the details below:</p>
            
            <div class="prescription-info">
                <strong>Prescription ID:</strong> {{ $prescription->id }}<br>
                <strong>Patient:</strong> {{ $patient->name }} (NIC: {{ $patient->nic }})<br>
                <strong>Hospital:</strong> {{ $hospital->name }}<br>
                <strong>Prescription Date:</strong> {{ \Carbon\Carbon::parse($prescription->date)->format('F j, Y') }}<br>
                <strong>Dispensed Date:</strong> {{ \Carbon\Carbon::now()->format('F j, Y g:i A') }}<br>
                @if($prescription->doctor)
                    <strong>Prescribed by:</strong> Dr. {{ $prescription->doctor->name }}<br>
                @endif
                @if($prescription->pharmacist)
                    <strong>Dispensed by:</strong> {{ $prescription->pharmacist->name }}<br>
                @endif
            </div>
            
            <h3>Dispensed Medicines:</h3>
            <div class="medicine-list">
                @forelse($prescription->medicines as $medicine)
                    <div class="medicine-item">
                        @if($medicine->is_external)
                            <div class="external-medicine">
                                <div class="medicine-name">{{ $medicine->name_of_external_medicine }} (External Medicine)</div>
                                <div class="medicine-details">
                                    <strong>Note:</strong> This medicine needs to be purchased from an external pharmacy
                                </div>
                            </div>
                        @else
                            <div class="medicine-name">{{ $medicine->name }}</div>
                        @endif
                        
                        <div class="medicine-details">
                            <strong>Dosage:</strong> {{ $medicine->dosage }} units<br>
                            <strong>Duration:</strong> {{ $medicine->days_supply }} days<br>
                            @if($medicine->duration)
                                <strong>Special Instructions:</strong> {{ $medicine->duration }}<br>
                            @endif
                        </div>
                        
                        @if($medicine->frequency)
                            <div class="frequency">
                                <strong>Frequency:</strong>
                                @php
                                    $frequency = json_decode($medicine->frequency, true);
                                @endphp
                                @if(isset($frequency['morning']) && $frequency['morning'])
                                    <span class="frequency-badge">Morning</span>
                                @endif
                                @if(isset($frequency['afternoon']) && $frequency['afternoon'])
                                    <span class="frequency-badge">Afternoon</span>
                                @endif
                                @if(isset($frequency['night']) && $frequency['night'])
                                    <span class="frequency-badge">Night</span>
                                @endif
                                @if(isset($frequency['if_needed']) && $frequency['if_needed'])
                                    <span class="frequency-badge">If Needed</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="medicine-item">
                        <p>No medicines were prescribed.</p>
                    </div>
                @endforelse
            </div>
            
            <div class="details">
                <h4>Important Instructions:</h4>
                <ul>
                    <li>Take medicines exactly as prescribed by your doctor</li>
                    <li>Complete the full course of medication even if you feel better</li>
                    <li>Store medicines in a cool, dry place away from children</li>
                    <li>Do not share your medicines with others</li>
                    <li>If you experience any side effects, contact your doctor immediately</li>
                    <li>For external medicines, please purchase them from licensed pharmacies</li>
                </ul>
            </div>
            
            @if($prescription->description)
                <div class="details">
                    <strong>Doctor's Notes:</strong><br>
                    {{ $prescription->description }}
                </div>
            @endif
            
            <p>If you have any questions about your medicines or need assistance, please contact {{ $hospital->name }} or consult with your doctor.</p>
            
            <p>Thank you for choosing our services. We wish you a speedy recovery!</p>
        </div>
        
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>{{ $hospital->name }} - Healthcare Services</p>
        </div>
    </div>
</body>
</html>
