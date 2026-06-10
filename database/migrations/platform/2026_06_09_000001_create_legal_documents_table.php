<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE legal_documents (
                id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                document_key    VARCHAR(100) NOT NULL,
                version         INTEGER NOT NULL DEFAULT 1,
                title           VARCHAR(255) NOT NULL,
                content         TEXT NOT NULL,
                effective_date  DATE NOT NULL DEFAULT CURRENT_DATE,
                is_active       BOOLEAN NOT NULL DEFAULT true,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_legal_documents_key_version UNIQUE (document_key, version)
            );

            CREATE INDEX idx_legal_documents_key ON legal_documents (document_key);
            CREATE INDEX idx_legal_documents_active ON legal_documents (document_key) WHERE is_active = true;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON legal_documents
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            INSERT INTO legal_documents (document_key, version, title, content, effective_date, is_active) VALUES (
                'hunter_info_certification',
                1,
                'Hunter Information Accuracy Certification',
                E'Hunter Information Accuracy Certification\n\nI, the undersigned applicant, hereby certify under penalty of perjury under the laws of the United States and the applicable state in which this application is submitted, that:\n\n1. All personal information I have provided in this lease application — including my name, date of birth, residential address, contact information, and emergency contact details — is true, accurate, and complete to the best of my knowledge.\n\n2. All driver''s license and state identification information provided is current, valid, and accurately reflects a government-issued document in my possession.\n\n3. All hunting license information provided is current, valid for the applicable license year and jurisdiction, and accurately reflects a hunting license I currently hold.\n\n4. All information provided for any additional hunters named in this application has been supplied with their knowledge and consent, and is true and accurate to the best of my knowledge.\n\n5. I understand and acknowledge that providing false, misleading, or materially inaccurate information in this application may result in:\n   • Immediate rejection or termination of this lease application;\n   • Forfeiture of any deposit or fees paid;\n   • Permanent suspension from the American Headhunter platform; and\n   • Civil and/or criminal liability under applicable state and federal law, including but not limited to laws governing false declarations, fraud, and identity misrepresentation.\n\n6. I authorize American Headhunter, LLC and the property landowner or their designated representative to verify the information provided herein through reasonable means, including but not limited to verification of hunting license validity with the applicable state wildlife agency.\n\nBy checking the certification checkbox, I acknowledge that I have read and understand this certification in its entirety, and I agree that my electronic acceptance constitutes a legally binding certification equivalent to a written signature under the Electronic Signatures in Global and National Commerce Act (E-Sign Act), 15 U.S.C. § 7001 et seq., and applicable state electronic signature laws.',
                CURRENT_DATE,
                true
            );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS legal_documents CASCADE');
    }
};
