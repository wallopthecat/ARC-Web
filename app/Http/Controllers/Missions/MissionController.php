<?php

namespace App\Http\Controllers\Missions;

use Log;
use Notification;
use App\Helpers\ArmaConfig;
use App\Models\Portal\User;
use Illuminate\Http\Request;
use App\Models\Missions\Mission;
use App\Http\Controllers\Controller;
use App\Notifications\MissionUpdated;
use App\Notifications\MissionVerified;
use App\Notifications\MissionPublished;
use Illuminate\Support\Facades\Storage;
use App\Models\Missions\MissionRevision;

class MissionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if ($request->exists('panel')) {
            return view('missions.' . $request->panel);
        }

        return view('missions.index');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if ($request->hasFile('file')) {
            $details = Mission::getDetailsFromName($request->file->getClientOriginalName());

            $mission = new Mission();
            $mission->user_id = $user->id;
            $mission->file_name = $request->file->getClientOriginalName();
            $mission->display_name = $request->file->getClientOriginalName();
            $mission->summary = '';
            $mission->mode = $details->mode;
            $mission->map_id = $details->map->id;
            $mission->pbo_path = '';
            $mission->save();

            // Store locally temporarily
            $path = $request->file->storeAs(
                "missions/{$user->id}/{$mission->id}",
                "original.pbo"
            );

            $mission->pbo_path = $path;
            $mission->save();

            // Unpack PBO and store configs in mission record as JSON objects
            $configs = $mission->storeConfigs(null, function($mission, $unpacked, $ext, $config) {
                $mission->display_name = trim($ext->onloadname, '.');
                $mission->summary = $ext->onloadmission;
                $mission->save();

                // Move to cloud storage
                $mission->deployCloudFiles($unpacked);
            }, storage_path("app/{$path}"));

            // If errors in configs, return message
            if (get_class($configs) == 'App\Helpers\ArmaConfigError') {
                $mission->delete();
                abort(400, $configs->message);
                return;
            }

            // Delete local temp files
            Storage::deleteDirectory("missions/{$user->id}");

            // Discord Message
            $mission->notify(new MissionPublished($mission, true));

            $users = User::all()->filter(function($user) use($mission) {
                return
                    $user->id != auth()->user()->id &&
                    ($user->hasPermission('mission:notes') ||
                    $user->id == $mission->user->id);
            });

            Notification::send($users, new MissionPublished($mission));

            return $mission->url();
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, Mission $mission)
    {
        if (!$mission->verified && !$mission->existsInOperation() && !auth()->user()->hasPermission('mission:see_new') && !$mission->isMine()) {
            return redirect('/hub/missions?403=1');
        }

        // Mark comment notifications as read
        foreach ($mission->commentNotifications() as $notification) {
            $notification->delete();
        }

        // Mark verified notifications as read
        foreach ($mission->verifiedNotifications() as $notification) {
            $notification->delete();
        }

        // Mark state notifications as read
        foreach ($mission->stateNotifications() as $notification) {
            $notification->delete();
        }

        if (!$request->ajax()) {
            return view('missions.show', compact('mission'));
        } else {
            return view('missions.show', compact('mission'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Mission $mission)
    {
        $user = auth()->user();

        if ($request->hasFile('file')) {
            $details = Mission::getDetailsFromName($request->file->getClientOriginalName());

            if ($mission) {
                $old_mission = clone $mission;
                $old_mission_displayname = $mission->display_name;
                $old_mission_summary = $mission->summary;

                $old_mission_cloud_pbo_dir = "missions/{$mission->user_id}/{$mission->id}/{$mission->exportedName('pbo')}";
                $old_mission_cloud_zip_dir = "missions/{$mission->user_id}/{$mission->id}/{$mission->exportedName('zip')}";

                Storage::cloud()->move($old_mission_cloud_pbo_dir, "x{$old_mission_cloud_pbo_dir}");
                Storage::cloud()->move($old_mission_cloud_zip_dir, "x{$old_mission_cloud_zip_dir}");

                $mission->file_name = $request->file->getClientOriginalName();
                $mission->display_name = $request->file->getClientOriginalName();
                $mission->mode = $details->mode;
                $mission->map_id = $details->map->id;
                $mission->save();

                // Store locally temporarily
                $path = $request->file->storeAs(
                    "missions/{$user->id}/{$mission->id}",
                    "original.pbo"
                );

                $mission->pbo_path = $path;
                $mission->save();

                // Unpack PBO and store configs in mission record as JSON objects
                $configs = $mission->storeConfigs(null, function($mission, $unpacked, $ext, $config) {
                    $mission->display_name = trim($ext->onloadname, '.');
                    $mission->summary = $ext->onloadmission;
                    $mission->save();

                    $mission = $mission->fresh('map');

                    // Move to cloud storage
                    $mission->deployCloudFiles($unpacked);
                }, storage_path("app/{$path}"));

                $path = "missions/{$mission->user_id}/{$mission->id}";

                // If errors in configs, return message
                if (get_class($configs) == 'App\Helpers\ArmaConfigError') {
                    Storage::deleteDirectory($path);

                    Storage::cloud()->move("x{$old_mission_cloud_pbo_dir}", $old_mission_cloud_pbo_dir);
                    Storage::cloud()->move("x{$old_mission_cloud_zip_dir}", $old_mission_cloud_zip_dir);

                    // Update the record with the old data
                    $mission->file_name = $old_mission->file_name;
                    $mission->display_name = $old_mission->display_name;
                    $mission->mode = $old_mission->mode;
                    $mission->map_id = $old_mission->map_id;
                    $mission->pbo_path = $old_mission->pbo_path;
                    $mission->display_name = $old_mission_displayname;
                    $mission->summary = $old_mission_summary;
                    $mission->save();

                    abort(400, $configs->message);
                    return;
                }

                // Delete local temp files
                Storage::deleteDirectory("missions/{$user->id}");

                // Delete old cloud files
                Storage::cloud()->delete("x{$old_mission_cloud_pbo_dir}");
                Storage::cloud()->delete("x{$old_mission_cloud_zip_dir}");

                // Create revision item
                $revision = MissionRevision::create([
                    'mission_id' => $mission->id,
                    'user_id' => auth()->user()->id
                ]);

                // Discord Message
                $mission->notify(new MissionUpdated($revision, true));

                $users = User::all()->filter(function($user) use($mission) {
                    return
                        $user->id != auth()->user()->id &&
                        ($user->hasPermission('mission:notes') ||
                        $user->id == $mission->user->id);
                });

                Notification::send($users, new MissionUpdated($revision));

                return view('missions.show', compact('mission'));
            }
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return void
     */
    public function destroy(Mission $mission)
    {
        if ($mission->existsInOperation() && !auth()->user()->hasPermission('mission:delete')) {
            return redirect('/hub/missions/' . $mission->id);
        }

        $mission->delete();

        return redirect('/hub/missions');
    }

    /**
     * Shows the given mission's briefing.
     *
     * @return \Illuminate\Http\Response
     */
    public function briefing(Request $request)
    {
        $mission = Mission::find($request->mission_id);
        $faction = $request->faction;
        return view('missions.briefing', compact('mission', 'faction'));
    }

    /**
     * Locks/unlocks the given briefing faction for the given mission.
     *
     * @return void
     */
    public function setBriefingLock(Request $request)
    {
        $mission = Mission::find($request->mission_id);

        if (!$mission->isMine() && !auth()->user()->hasPermission('mission:set_briefing_locks')) {
            abort(403, 'You are not authorised to edit this mission');
            return;
        }

        $mission->lockBriefing($request->faction, $request->locked);
    }

    /**
     * Updates the mission verification boolean.
     *
     * @return any
     */
    public function updateVerification(Request $request, Mission $mission)
    {
        $mission->verified = !$mission->verified;

        if ($mission->verified) {
            $mission->verified_by = auth()->user()->id;
        } else {
            $mission->verified_by = null;
        }

        $mission->save();

        if ($mission->verified) {
            // Discord Message
            $mission->notify(new MissionVerified($mission, true));

            $users = User::all()->filter(function($user) use($mission) {
                return
                    $user->id != auth()->user()->id &&
                    (
                        $user->hasPermission('mission:verification') ||
                        $user->id == $mission->user->id
                    );
            });

            Notification::send($users, new MissionVerified($mission));
        }

        $updated_by = auth()->user()->username;

        return "Verified by {$updated_by}";
    }

    /**
     * Gets the full download URL for the given mission and format.
     *
     * @return string
     */
    public function download(Mission $mission, $format = 'pbo')
    {
        return $mission->download($format);
    }

    /**
     * Shows the given panel for the given mission.
     *
     * @return any
     */
    public function panel(Request $request, Mission $mission, $panel)
    {
        if (!$mission->verified && !$mission->existsInOperation() && !auth()->user()->hasPermission('mission:see_new') && !$mission->isMine()) {
            return redirect('/hub/missions?403=1');
        }

        if (!$request->ajax()) {
            return view('missions.show', compact('mission', 'panel'));
        } else {
            return view('missions.show.' . strtolower($panel), compact('mission'));
        }
    }
}
