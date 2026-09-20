<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * L'affectation d'un correcteur à une évaluation.
 *
 * Cette table est la seule qui décide du périmètre d'un correcteur : sa file ne
 * contient que les copies des évaluations listées ici. La retirer lui ferme une
 * évaluation sans toucher aux autres.
 */
class GraderAssignment extends Model
{
    protected $fillable = ['grader_id', 'form_id', 'created_by'];

    public function grader()
    {
        return $this->belongsTo(Grader::class);
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }
}
