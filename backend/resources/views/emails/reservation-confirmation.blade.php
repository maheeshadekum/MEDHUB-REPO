<!DOCTYPE html>
<html>
<head>
    <title>{{ $type === 'clinic' ? 'Clinic' : 'OPD' }} Appointment Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .content { padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .footer { margin-top: 20px; font-size: 12px; color: #666; }
        .details { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>{{ $type === 'clinic' ? 'Clinic' : 'OPD' }} Appointment Confirmation</h2>
        </div>
        
        <div class="content">
            <p>Dear {{ $reservation->patient->name }},</p>
            
            <p>Your {{ $type === 'clinic' ? 'clinic' : 'OPD' }} appointment has been confirmed. Here are the details:</p>
            
            <div class="details">
                @if($type === 'clinic')
                    <strong>Clinic:</strong> {{ $reservation->clinicDate->clinic->name }}<br>
                    <strong>Hospital:</strong> {{ $reservation->clinicDate->clinic->hospital->name ?? 'N/A' }}<br>
                    <strong>Date:</strong> {{ $reservation->clinicDate->date->format('F j, Y') }}<br>
                @else
                    <strong>Hospital:</strong> {{ $reservation->opdDate->hospital->name }}<br>
                    <strong>Date:</strong> {{ $reservation->opdDate->date->format('F j, Y') }}<br>
                @endif
                <strong>Token Number:</strong> {{ $reservation->token_number }}<br>
                <strong>Time:</strong> {{ $reservation->start_time->format('h:i A') }} - {{ $reservation->end_time->format('h:i A') }}<br>
                <strong>Reservation Type:</strong> {{ ucfirst($reservation->type) }}
            </div>
            
            <p><strong>Important Notes:</strong></p>
            <ul>
                <li>Please arrive 15 minutes before your appointment time</li>
                <li>Bring a valid ID and any relevant medical documents</li>
                <li>Contact the hospital if you need to reschedule or cancel</li>
            </ul>
            
            <p>If you have any questions, please contact the hospital directly.</p>
            
            <p>Thank you for choosing our services.</p>
        </div>
        
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
