<?php

// Report columns and values in exported files (A-16, FR-RPT-03)
return [
    'yes' => 'Yes',
    'no' => 'No',
    'columns' => [
        'number' => 'Number', 'type' => 'Type', 'status' => 'Status', 'created_at' => 'Created', 'first_sent_at' => 'First quote sent',
        'response_hours' => 'Response time (hours)', 'lost_reason' => 'Lost reason', 'source' => 'Source', 'urgency' => 'Priority',
        'sla_due_at' => 'Triage deadline', 'sla_breached' => 'Deadline missed', 'age_days' => 'Age (days)', 'part_number' => 'Part number',
        'name' => 'Name', 'quantity' => 'Quantity', 'revenue' => 'Revenue (OMR)', 'available' => 'Available', 'line' => 'Business line',
        'branch' => 'Branch', 'invoices' => 'Invoices', 'total' => 'Total (OMR)', 'collected' => 'Collected (OMR)',
    ],
    'values' => [
        'type' => ['equipment' => 'Equipment', 'parts' => 'Parts', 'service' => 'Service'],
        'status' => [
            'submitted' => 'Submitted', 'in_review' => 'In review', 'quoted' => 'Quoted', 'won' => 'Won', 'lost' => 'Lost', 'cancelled' => 'Cancelled',
            'triaged' => 'Triaged', 'awaiting_approval' => 'Awaiting approval', 'approved' => 'Approved', 'in_service' => 'At the workshop',
            'completed' => 'Ready for pickup', 'closed' => 'Closed', 'declined' => 'Declined',
        ],
        'source' => ['customer' => 'Platform', 'walk_in' => 'Walk-in', 'contract' => 'Contract', 'system' => 'System', 'staff' => 'Staff', 'web' => 'Website', 'app' => 'App'],
        'urgency' => ['normal' => 'Normal', 'urgent' => 'Urgent'],
        'line' => ['parts' => 'Spare parts', 'service' => 'Service', 'equipment' => 'Equipment', 'other' => 'Other'],
    ],
];
