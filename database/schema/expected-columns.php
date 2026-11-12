<?php

/**
 * Kolom setiap tabel ERP (tipe MySQL, boleh kosong, nilai bawaan) setelah semua
 * migrasi dijalankan. Acuan untuk migrasi 2026_11_11_150000 (menambah kolom
 * yang hilang di database lama) dan `migrate-cwp.sh check`.
 *
 * Dibuat ulang dengan `php artisan schema:snapshot` dari database yang sudah
 * menjalankan semua migrasi; jangan diubah manual.
 */

return array (
  'absences' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'start_time' => 
    array (
      'type' => 'datetime',
      'nullable' => true,
    ),
    'end_time' => 
    array (
      'type' => 'datetime',
      'nullable' => true,
    ),
    'category' => 
    array (
      'type' => 'varchar(20)',
      'nullable' => true,
      'default' => 'cuti',
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'work_report' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'sick_letter' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'rejection_reason' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'approval_status' => 
    array (
      'type' => 'enum(\'pending\',\'approved\',\'rejected\')',
      'nullable' => false,
      'default' => 'pending',
    ),
    'approved_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'activity_log' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'log_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'subject_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'event' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'subject_id' => 
    array (
      'type' => 'char(36)',
      'nullable' => true,
    ),
    'causer_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'causer_id' => 
    array (
      'type' => 'char(36)',
      'nullable' => true,
    ),
    'properties' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'batch_uuid' => 
    array (
      'type' => 'char(36)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'advances' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'submission_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'project_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'payment_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'keterangan_advance' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'category' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'attachment' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'attachments' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'paid' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
      'default' => '0.00',
    ),
    'paid_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'overrun_approved_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'overrun_approved_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'realisasi_notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'realisasi_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'realisasi_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'reference_number' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'rejection_reason' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'attachment_pay' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'paid_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'pay_parallel' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'ai_usages' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'date' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'calls' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'failures' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'input_tokens' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'output_tokens' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'announcements' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'content' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'start_date' => 
    array (
      'type' => 'datetime',
      'nullable' => false,
    ),
    'end_date' => 
    array (
      'type' => 'datetime',
      'nullable' => false,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'areas' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'city_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'translations' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
  ),
  'assets' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'uuid' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'nama_barang' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'tanggal_pembelian' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'deskripsi' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'pembelian_dari' => 
    array (
      'type' => 'enum(\'marketplace\',\'langsung\')',
      'nullable' => false,
    ),
    'kondisi' => 
    array (
      'type' => 'enum(\'baru\',\'bekas\')',
      'nullable' => false,
    ),
    'maintenance_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'penanggung_jawab_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'bon_invoice' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'foto' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'harga_barang' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
    ),
    'kelompok_harta' => 
    array (
      'type' => 'varchar(30)',
      'nullable' => true,
    ),
    'metode_penyusutan' => 
    array (
      'type' => 'varchar(20)',
      'nullable' => false,
      'default' => 'garis_lurus',
    ),
    'tanggal_dilepas' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'bank_mutations' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'payment_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'fingerprint' => 
    array (
      'type' => 'varchar(64)',
      'nullable' => false,
    ),
    'mutation_date' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'direction' => 
    array (
      'type' => 'enum(\'in\',\'out\')',
      'nullable' => false,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'balance' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'targets' => 
    array (
      'type' => 'json',
      'nullable' => false,
    ),
    'upload' => 
    array (
      'type' => 'varchar(36)',
      'nullable' => false,
    ),
    'recorded_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'budget_logs' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'budget_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'change_amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
    ),
    'action' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'note' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'budgets' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'cities' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'country_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'translations' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'timezone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'lat' => 
    array (
      'type' => 'decimal(8,2)',
      'nullable' => true,
    ),
    'lng' => 
    array (
      'type' => 'decimal(8,2)',
      'nullable' => true,
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
  ),
  'companies' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'name_key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'alamat' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'npwp' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'merged_into_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'company_contacts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'company_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'name_key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'jabatan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'telephone' => 
    array (
      'type' => 'varchar(50)',
      'nullable' => true,
    ),
    'email' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'merged_into_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'connected_account_metrics' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'connected_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'date' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'followers' => 
    array (
      'type' => 'bigint',
      'nullable' => false,
      'default' => '0',
    ),
    'following' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'media_count' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'reach' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'impressions' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'likes' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'comments' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'saves' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'shares' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'engagement' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'profile_visits' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'website_clicks' => 
    array (
      'type' => 'bigint',
      'nullable' => true,
      'default' => '0',
    ),
    'engagement_rate' => 
    array (
      'type' => 'decimal(8,4)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'connected_accounts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'provider' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'account_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'username' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'profile_picture' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'access_token' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'token_expires_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'account_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'personal',
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'is_active' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '1',
    ),
    'last_synced_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'metadata' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'contract_items' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'contract_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'parent_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'category' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'is_category_header' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'item' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'due_date' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'notes' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'type' => 
    array (
      'type' => 'enum(\'fee\',\'third_party\',\'sow\')',
      'nullable' => false,
      'default' => 'fee',
    ),
    'quantity' => 
    array (
      'type' => 'int',
      'nullable' => true,
      'default' => '1',
    ),
    'unit' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'sort_order' => 
    array (
      'type' => 'int',
      'nullable' => false,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'estimate' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
  ),
  'contracts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'deal_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'contract_number' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'document_title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'opening_text' => 
    array (
      'type' => 'longtext',
      'nullable' => true,
    ),
    'articles' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'closing_text' => 
    array (
      'type' => 'longtext',
      'nullable' => true,
    ),
    'header_image' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'footer_image' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'client_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'type' => 
    array (
      'type' => 'enum(\'editor\',\'file\')',
      'nullable' => false,
      'default' => 'editor',
    ),
    'content' => 
    array (
      'type' => 'longtext',
      'nullable' => true,
    ),
    'effective_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'expiration_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'duration_months' => 
    array (
      'type' => 'int',
      'nullable' => true,
    ),
    'total_value' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'down_payment_percent' => 
    array (
      'type' => 'decimal(5,2)',
      'nullable' => false,
      'default' => '50.00',
    ),
    'payment_type' => 
    array (
      'type' => 'enum(\'full\',\'dp_monthly\',\'monthly\')',
      'nullable' => false,
      'default' => 'full',
    ),
    'installment_months' => 
    array (
      'type' => 'tinyint unsigned',
      'nullable' => true,
    ),
    'lampiran_title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'lampiran_description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'table_a_title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'table_b_title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'table_b_description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'has_third_party' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'max_revisions' => 
    array (
      'type' => 'int',
      'nullable' => false,
      'default' => '3',
    ),
    'platform' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'content_count' => 
    array (
      'type' => 'int',
      'nullable' => true,
    ),
    'scope_of_work' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'project_description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'additional_terms' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'force_majeure_extended' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'termination_notice_days' => 
    array (
      'type' => 'int',
      'nullable' => false,
      'default' => '30',
    ),
    'penalty_clause' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'client_name_full' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'client_address' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'client_phone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'client_email' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'client_signatory_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'client_signatory_title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'agency_signatory_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'agency_signatory_title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'file_path' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'client_name_old' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'table_a_columns' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'table_a_total_label' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'table_b_columns' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'layout' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'signing_city' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'client_legal_basis' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
  ),
  'countries' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'code' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'phone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'translations' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'timezones' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'numeric_code' => 
    array (
      'type' => 'char(3)',
      'nullable' => true,
    ),
    'iso3' => 
    array (
      'type' => 'char(3)',
      'nullable' => true,
    ),
    'nationality' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'capital' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'tld' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'native' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'region' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'currency' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'currency_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'currency_symbol' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'wikiDataId' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'lat' => 
    array (
      'type' => 'decimal(8,2)',
      'nullable' => true,
    ),
    'lng' => 
    array (
      'type' => 'decimal(8,2)',
      'nullable' => true,
    ),
    'emoji' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'emojiU' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'flag' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
  ),
  'currencies' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'arabic' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'iso' => 
    array (
      'type' => 'char(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'exchange_rate' => 
    array (
      'type' => 'double',
      'nullable' => true,
    ),
    'symbol' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'translations' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
  ),
  'deal_product' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'deal_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'product_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'deals' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'nama_pic' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'product_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'lead_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'nama_perusahaan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'email' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'telephone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'source' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'alamat' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(50)',
      'nullable' => false,
      'default' => 'Negosiasi',
    ),
    'NPWP' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'layanan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'notes' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'company_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'contact_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
  ),
  'events' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'start_time' => 
    array (
      'type' => 'datetime',
      'nullable' => false,
    ),
    'end_time' => 
    array (
      'type' => 'datetime',
      'nullable' => true,
    ),
    'color' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => '#007bff',
    ),
    'google_event_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'create_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'task_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'expenses' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'project_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'payment_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'submission_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'category' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'keterangan_expense' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'approved_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'paid' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'paid_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'reference' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'attachment' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'rejection_reason' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'attachment_pay' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'failed_jobs' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'uuid' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'connection' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'queue' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'payload' => 
    array (
      'type' => 'longtext',
      'nullable' => false,
    ),
    'exception' => 
    array (
      'type' => 'longtext',
      'nullable' => false,
    ),
    'failed_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'filament_exceptions_table' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'code' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'message' => 
    array (
      'type' => 'longtext',
      'nullable' => false,
    ),
    'file' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'line' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
    'trace' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'method' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'path' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'query' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'body' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'cookies' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'headers' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'ip' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'financial_ai_reports' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'analysis' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'metrics' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'period_start' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'period_end' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'version' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'v1',
    ),
  ),
  'folder_has_models' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'model_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'folder_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'folders' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'model_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'collection' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'description' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'icon' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'color' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'is_protected' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'password' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'is_hidden' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'is_favorite' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'is_public' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
    'has_user_access' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'user_id' => 
    array (
      'type' => 'char(36)',
      'nullable' => true,
    ),
    'user_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
  ),
  'invoice_logs' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'invoice_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'info',
    ),
    'log' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'invoice_metas' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'invoice_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'value' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'text',
    ),
    'group' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'general',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'invoices' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'deal_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'parent_invoice_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'payment_type' => 
    array (
      'type' => 'enum(\'full\',\'dp\',\'final\',\'remaining\')',
      'nullable' => false,
      'default' => 'full',
    ),
    'dp_percentage' => 
    array (
      'type' => 'tinyint unsigned',
      'nullable' => true,
    ),
    'uuid' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'proforma_uuid' => 
    array (
      'type' => 'varchar(50)',
      'nullable' => true,
    ),
    'for_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'for_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'from_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'from_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'branch_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'category_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'nama_perusahaan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'telephone' => 
    array (
      'type' => 'varchar(50)',
      'nullable' => true,
    ),
    'alamat' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'nama_pic' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'subtotal' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
      'default' => '0.00',
    ),
    'total_discount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
      'default' => '0.00',
    ),
    'total_vat' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
      'default' => '0.00',
    ),
    'phone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'address' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'estimate',
    ),
    'type_payment' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'po_number' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'faktur' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'faktur_file' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'pending',
    ),
    'total' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'discount' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'shipping' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'vat' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'paid' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'grand_total' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'due_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'payment_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'is_offer' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'insert_in_to_inventory' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'send_email' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'currency_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'is_bank_transfer' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'bank_account' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_account_owner' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_iban' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_swift' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_address' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_branch' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_city' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_country' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'delivery_note_number' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
  ),
  'invoices_items' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'item',
    ),
    'invoice_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'item_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'item_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'item' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'description' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'note' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'qty' => 
    array (
      'type' => 'double',
      'nullable' => true,
      'default' => '1',
    ),
    'price' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'original_price' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'discount' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'vat' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'vat_percentage' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'total' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'returned_qty' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'returned' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'is_free' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'is_returned' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'options' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'item_advance' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'advance_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'quantity' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
    'price' => 
    array (
      'type' => 'decimal(10,2)',
      'nullable' => false,
    ),
    'subtotal' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'item_expenses' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'expense_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'quantity' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
    'price' => 
    array (
      'type' => 'decimal(10,2)',
      'nullable' => false,
    ),
    'subtotal' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'jobs' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'queue' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'payload' => 
    array (
      'type' => 'longtext',
      'nullable' => false,
    ),
    'attempts' => 
    array (
      'type' => 'tinyint unsigned',
      'nullable' => false,
    ),
    'reserved_at' => 
    array (
      'type' => 'int unsigned',
      'nullable' => true,
    ),
    'available_at' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
    ),
  ),
  'languages' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'iso' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'arabic' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'translations' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
  ),
  'lead_product' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'lead_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'product_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'leads' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'nama_pic' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'nama_perusahaan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'email' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'telephone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'source' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'Dalam Komunikasi',
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'is_converted' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'product_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
  ),
  'locations' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'model_id' => 
    array (
      'type' => 'int unsigned',
      'nullable' => true,
    ),
    'street' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'area_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'city_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'country_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'home_number' => 
    array (
      'type' => 'int unsigned',
      'nullable' => true,
    ),
    'flat_number' => 
    array (
      'type' => 'int unsigned',
      'nullable' => true,
    ),
    'floor_number' => 
    array (
      'type' => 'int unsigned',
      'nullable' => true,
    ),
    'mark' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'map_url' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'note' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'lat' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'lng' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'zip' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'is_main' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'media' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'model_id' => 
    array (
      'type' => 'char(36)',
      'nullable' => false,
    ),
    'uuid' => 
    array (
      'type' => 'char(36)',
      'nullable' => true,
    ),
    'collection_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'file_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'mime_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'disk' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'conversions_disk' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'size' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'manipulations' => 
    array (
      'type' => 'json',
      'nullable' => false,
    ),
    'custom_properties' => 
    array (
      'type' => 'json',
      'nullable' => false,
    ),
    'generated_conversions' => 
    array (
      'type' => 'json',
      'nullable' => false,
    ),
    'responsive_images' => 
    array (
      'type' => 'json',
      'nullable' => false,
    ),
    'order_column' => 
    array (
      'type' => 'int unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'media_has_models' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'model_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'media_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'meta_ad_accounts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'client_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'ad_account_id' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => false,
    ),
    'account_name' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'access_token' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'token_expires_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'is_active' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '1',
    ),
    'last_synced_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'meta_campaign_metrics' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'meta_ad_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'campaign_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'campaign_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'objective' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'date' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'spend' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'impressions' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'clicks' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'reach' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'conversions' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'ctr' => 
    array (
      'type' => 'decimal(8,4)',
      'nullable' => false,
      'default' => '0.0000',
    ),
    'cpc' => 
    array (
      'type' => 'decimal(10,4)',
      'nullable' => false,
      'default' => '0.0000',
    ),
    'cpm' => 
    array (
      'type' => 'decimal(10,4)',
      'nullable' => false,
      'default' => '0.0000',
    ),
    'roas' => 
    array (
      'type' => 'decimal(8,4)',
      'nullable' => false,
      'default' => '0.0000',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'migrations' => 
  array (
    'id' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'migration' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'batch' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
  ),
  'model_has_permissions' => 
  array (
    'permission_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'model_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
  ),
  'model_has_roles' => 
  array (
    'role_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'model_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
  ),
  'notifications' => 
  array (
    'id' => 
    array (
      'type' => 'char(36)',
      'nullable' => false,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'notifiable_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'notifiable_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'data' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'read_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'password_reset_tokens' => 
  array (
    'email' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'token' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'payment_accounts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'bank',
    ),
    'account_number' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'account_holder' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'bank_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'initial_balance' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
      'default' => '0.00',
    ),
    'current_balance' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
      'default' => '0.00',
    ),
    'is_active' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '1',
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'payroll_components' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => false,
    ),
    'type' => 
    array (
      'type' => 'varchar(10)',
      'nullable' => false,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'sort' => 
    array (
      'type' => 'smallint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'payslip_items' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'payslip_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => false,
    ),
    'type' => 
    array (
      'type' => 'varchar(10)',
      'nullable' => false,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'sort' => 
    array (
      'type' => 'smallint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'from_component' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'payslips' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'period' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'status' => 
    array (
      'type' => 'varchar(10)',
      'nullable' => false,
      'default' => 'draft',
    ),
    'employee_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'employee_nik' => 
    array (
      'type' => 'varchar(30)',
      'nullable' => true,
    ),
    'position' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'division' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'npwp' => 
    array (
      'type' => 'varchar(30)',
      'nullable' => true,
    ),
    'earnings' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'deductions' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'net' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'finalized_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'finalized_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'sent_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'paid_at' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'paid_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'payment_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'performance_indicators' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'task_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'nilai' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
    'deskripsi_pekerjaan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'permissions' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'guard_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'personal_access_tokens' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'tokenable_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'tokenable_id' => 
    array (
      'type' => 'char(36)',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'token' => 
    array (
      'type' => 'varchar(64)',
      'nullable' => false,
    ),
    'abilities' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'last_used_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'expires_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'products' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'nama_layanan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'detail_layanan' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'harga_layanan' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
    ),
    'pajak' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'total_harga' => 
    array (
      'type' => 'decimal(15,0)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'project_timeline_logs' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'subject_type' => 
    array (
      'type' => 'varchar(20)',
      'nullable' => false,
    ),
    'subject_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'project_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'user_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'action' => 
    array (
      'type' => 'varchar(20)',
      'nullable' => false,
    ),
    'title' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'diff' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => false,
    ),
  ),
  'project_timelines' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'project_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'nama_phase' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'start_date' => 
    array (
      'type' => 'datetime',
      'nullable' => false,
    ),
    'end_date' => 
    array (
      'type' => 'datetime',
      'nullable' => false,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'ongoing',
    ),
    'color' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
  ),
  'projects' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'nama_project' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'deal_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'invoice_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'detail_project' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(50)',
      'nullable' => true,
      'default' => 'On Progress',
    ),
    'timeline_mulai' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'timeline_selesai' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'nama_perusahaan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'alamat' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'nama_pic' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'sender_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'sender_address' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'sender_phone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'recipient_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'recipient_address' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'recipient_phone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'timeline' => 
    array (
      'type' => 'datetime',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'purchase_requests' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'item_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'quantity' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'reference_link' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'price' => 
    array (
      'type' => 'decimal(10,2)',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'enum(\'pending\',\'approved\',\'rejected\')',
      'nullable' => false,
      'default' => 'pending',
    ),
    'reason' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'requested_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'quotation_items' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'quotation_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'item' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'product_name' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'qty' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '1',
    ),
    'price' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'discount' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'vat_percentage' => 
    array (
      'type' => 'double',
      'nullable' => true,
      'default' => '0',
    ),
    'vat' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'total' => 
    array (
      'type' => 'double',
      'nullable' => false,
      'default' => '0',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'quotations' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'uuid' => 
    array (
      'type' => 'varchar(50)',
      'nullable' => true,
    ),
    'date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'due_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'subtotal' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'total_discount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'total_vat' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'grand_total' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'deal_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'nama_perusahaan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'telephone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'alamat' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'nama_pic' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'email' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'pending',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'realisasi_batches' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'advance_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'batch_number' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
      'default' => '1',
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'uploaded_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'realisasi_files' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'realisasi_item_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'original_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'filepath' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'filesize' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'mime_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'realisasi_items' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'realisasi_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'quantity' => 
    array (
      'type' => 'decimal(12,2)',
      'nullable' => false,
      'default' => '1.00',
    ),
    'price' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'subtotal' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
      'default' => '0.00',
    ),
    'receipt_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'realizasis' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'advance_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'batch_number' => 
    array (
      'type' => 'int unsigned',
      'nullable' => false,
      'default' => '1',
    ),
    'uploaded_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'notes' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'role_has_permissions' => 
  array (
    'permission_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'role_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
  ),
  'roles' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'guard_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'sessions' => 
  array (
    'id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'ip_address' => 
    array (
      'type' => 'varchar(45)',
      'nullable' => true,
    ),
    'user_agent' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'payload' => 
    array (
      'type' => 'longtext',
      'nullable' => false,
    ),
    'last_activity' => 
    array (
      'type' => 'int',
      'nullable' => false,
    ),
  ),
  'settings' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'group' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'locked' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'payload' => 
    array (
      'type' => 'json',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'social_accounts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'client_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'platform' => 
    array (
      'type' => 'enum(\'instagram\',\'facebook\')',
      'nullable' => false,
    ),
    'platform_account_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'username' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'page_name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'profile_picture_url' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'followers_count' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'following_count' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'media_count' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'fan_count' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'access_token' => 
    array (
      'type' => 'text',
      'nullable' => false,
    ),
    'token_expires_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'is_active' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '1',
    ),
    'sync_status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'pending',
    ),
    'last_synced_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'last_sync_started_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'last_sync_error' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'last_sync_attempt' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'sync_metadata' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'social_post_metrics' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'social_post_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'likes' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'comments' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'shares' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'reach' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'impressions' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'engagement_rate' => 
    array (
      'type' => 'decimal(8,4)',
      'nullable' => false,
      'default' => '0.0000',
    ),
    'saved' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'video_views' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'profile_visits' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'reactions' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'clicks' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'negative_feedback' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'default' => '0',
    ),
    'synced_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'social_posts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'social_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'platform_post_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'media_type' => 
    array (
      'type' => 'enum(\'image\',\'video\',\'carousel_album\',\'reels\',\'story\',\'link\',\'status\')',
      'nullable' => true,
    ),
    'caption' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'permalink' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'thumbnail_url' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'media_url' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'posted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'social_sync_logs' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'social_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'sync_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'error_message' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'response_summary' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'records_processed' => 
    array (
      'type' => 'int',
      'nullable' => false,
      'default' => '0',
    ),
    'execution_time_ms' => 
    array (
      'type' => 'int',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'task_stages' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'color' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => '#94a3b8',
    ),
    'background_color' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => '#f8fafc',
    ),
    'sort_order' => 
    array (
      'type' => 'int',
      'nullable' => false,
      'default' => '0',
    ),
    'is_active' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '1',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'task_user' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'task_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'tasks' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'creator_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'nama_task' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'project_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'deal_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'detail_task' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'mulai_task' => 
    array (
      'type' => 'datetime',
      'nullable' => true,
    ),
    'selesai_task' => 
    array (
      'type' => 'datetime',
      'nullable' => true,
    ),
    'status' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'stage_key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'sort_order' => 
    array (
      'type' => 'decimal(20,10)',
      'nullable' => true,
    ),
    'proritas' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'nama_layanan' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'done_note' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'google_event_id' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
  ),
  'telegram_receipts' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'file_unique_id' => 
    array (
      'type' => 'varchar(64)',
      'nullable' => false,
    ),
    'scope' => 
    array (
      'type' => 'varchar(40)',
      'nullable' => false,
      'default' => 'pengajuan',
    ),
    'user_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'reference' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'phash' => 
    array (
      'type' => 'varchar(16)',
      'nullable' => true,
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => true,
    ),
    'receipt_date' => 
    array (
      'type' => 'date',
      'nullable' => true,
    ),
    'shop' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'transactions' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'payment_account_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'expense',
    ),
    'amount' => 
    array (
      'type' => 'decimal(15,2)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'reference_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'reference_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'category' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
      'default' => 'other',
    ),
    'transaction_date' => 
    array (
      'type' => 'date',
      'nullable' => false,
    ),
    'created_by' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'attachment' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'typables' => 
  array (
    'type_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'typables_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'typables_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
  ),
  'types' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'order' => 
    array (
      'type' => 'int',
      'nullable' => false,
      'default' => '0',
    ),
    'parent_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'model_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'for' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'posts',
    ),
    'type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
      'default' => 'category',
    ),
    'name' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'description' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'color' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'icon' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'is_activated' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => true,
      'default' => '1',
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'types_metas' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'model_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => true,
    ),
    'model_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'type_id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
    ),
    'key' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'value' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
  ),
  'users' => 
  array (
    'id' => 
    array (
      'type' => 'bigint unsigned',
      'nullable' => false,
      'auto' => true,
    ),
    'username' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'firstname' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'lastname' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'email' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'email_verified_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'password' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => false,
    ),
    'remember_token' => 
    array (
      'type' => 'varchar(100)',
      'nullable' => true,
    ),
    'push_subscription' => 
    array (
      'type' => 'json',
      'nullable' => true,
    ),
    'push_enabled' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '0',
    ),
    'device_type' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'device_token' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'created_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'updated_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'deleted_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'nik' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'phone' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'telegram_chat_id' => 
    array (
      'type' => 'varchar(32)',
      'nullable' => true,
    ),
    'telegram_linked_at' => 
    array (
      'type' => 'timestamp',
      'nullable' => true,
    ),
    'address' => 
    array (
      'type' => 'text',
      'nullable' => true,
    ),
    'position' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'npwp' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'ktp' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'file_npwp' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'division' => 
    array (
      'type' => 'varchar(255)',
      'nullable' => true,
    ),
    'is_active' => 
    array (
      'type' => 'tinyint(1)',
      'nullable' => false,
      'default' => '1',
    ),
  ),
);
