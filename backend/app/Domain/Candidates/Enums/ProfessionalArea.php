<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Área profissional de interesse (usada em filtros, dashboard e relatórios). */
enum ProfessionalArea: string
{
    use LabeledEnum;

    case Medical = 'medical';
    case Nursing = 'nursing';
    case ClinicalSupport = 'clinical_support';
    case PatientService = 'patient_service';
    case Administrative = 'administrative';
    case Finance = 'finance';
    case HumanResources = 'human_resources';
    case InformationTechnology = 'information_technology';
    case Commercial = 'commercial';
    case Logistics = 'logistics';
    case Maintenance = 'maintenance';
    case GeneralServices = 'general_services';
    case Legal = 'legal';
    case Management = 'management';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Medical => 'Médica',
            self::Nursing => 'Enfermagem',
            self::ClinicalSupport => 'Apoio clínico e diagnóstico',
            self::PatientService => 'Atendimento e recepção',
            self::Administrative => 'Administrativo',
            self::Finance => 'Financeiro e faturamento',
            self::HumanResources => 'Recursos humanos',
            self::InformationTechnology => 'Tecnologia da informação',
            self::Commercial => 'Comercial e marketing',
            self::Logistics => 'Compras, estoque e logística',
            self::Maintenance => 'Manutenção e infraestrutura',
            self::GeneralServices => 'Higienização e serviços gerais',
            self::Legal => 'Jurídico',
            self::Management => 'Gestão e liderança',
            self::Other => 'Outra',
        };
    }
}
