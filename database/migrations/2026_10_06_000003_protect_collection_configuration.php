<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('place_categories')->leftJoin('categories', 'categories.id', '=', 'place_categories.category_id')->whereRaw("NOT (categories.supported AND ((categories.code = 'standalone_part' AND place_categories.subtype IN ('hdd','ssd','ict_cooling_fan')) OR (categories.code = 'peripheral' AND place_categories.subtype IN ('router','keyboard')) OR (categories.code IN ('laptop','desktop','phone','flat_monitor','printer') AND place_categories.subtype = '')))")->exists()) {
            throw new RuntimeException('Collection configuration blocked: unexpected compatibility data.');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_place_capability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM categories c WHERE c.id = NEW.category_id AND c.supported AND
        ((c.code = 'standalone_part' AND NEW.subtype IN ('hdd','ssd','ict_cooling_fan')) OR
         (c.code = 'peripheral' AND NEW.subtype IN ('router','keyboard')) OR
         (c.code IN ('laptop','desktop','phone','flat_monitor','printer') AND NEW.subtype = ''))) THEN
        RAISE EXCEPTION 'Point capability requires an explicit supported category and subtype';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER explicit_place_capability BEFORE INSERT OR UPDATE ON place_categories FOR EACH ROW EXECUTE FUNCTION protect_place_capability();
CREATE FUNCTION protect_service_area_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR ROW(NEW.id,NEW.version_code,NEW.geometry,NEW.provenance,NEW.synthetic) IS DISTINCT FROM ROW(OLD.id,OLD.version_code,OLD.geometry,OLD.provenance,OLD.synthetic) THEN
        RAISE EXCEPTION 'Service geometry changes require a new version';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER permanent_service_area_version BEFORE UPDATE OR DELETE ON service_areas FOR EACH ROW EXECUTE FUNCTION protect_service_area_version();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Collection configuration requires a reviewed forward migration; rollback is disabled.');
    }
};
