/**
 * Client-side port of YataiDON's in-game Don-chan renderer
 * (YataiDON/src/objects/global/chara_3d.cpp). Loads a kigurumi cos/{id}.glb, recolors
 * materials by RGB channel dominance, stamps a face frame, draws an inverted-hull
 * outline, and can screenshot the canvas to a PNG data URL for use as a profile picture.
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

export type RGB = { r: number; g: number; b: number };

/** glTF material.extras.shaderType tags (mirror of parse_glb_material_indices). */
const SHADER_RECOLOR = 'taikoEffectChangeColors';
const SHADER_FACE = 'taikoEffectFace';

const FACE_FRAME = 128; // each face sheet stacks 12 expression frames of 128x128.
export const FACE_FRAME_COUNT = 12;

export function hexToRgb(hex: string): RGB {
    const value = parseInt(hex.replace('#', ''), 16);

    return { r: (value >> 16) & 255, g: (value >> 8) & 255, b: value & 255 };
}

type RecolorUniforms = {
    uBody: { value: THREE.Color };
    uFace: { value: THREE.Color };
    uRim: { value: THREE.Color };
};

type RecolorEntry = {
    material: THREE.MeshBasicMaterial;
    uniforms: RecolorUniforms;
};

/**
 * Fragment-shader port of recolor_texture (chara_3d.cpp): channel-dominance remap of the
 * channel-coded swatch texture. Runs on the GPU against the original texture/UVs, so there
 * is no canvas readback or orientation guessing. Replaces the map sampling chunk.
 */
const RECOLOR_INJECT = /* glsl */ `#include <map_fragment>
{
    float s = max( sampledDiffuseColor.r, max( sampledDiffuseColor.g, sampledDiffuseColor.b ) );
    float w = min( sampledDiffuseColor.r, min( sampledDiffuseColor.g, sampledDiffuseColor.b ) );
    if ( s > 0.05 && ( s - w ) > 0.08 ) {
        vec3 don = uBody;
        if ( sampledDiffuseColor.b > sampledDiffuseColor.r && sampledDiffuseColor.b >= sampledDiffuseColor.g ) {
            don = uRim;
        } else if ( sampledDiffuseColor.g > sampledDiffuseColor.r && sampledDiffuseColor.g > sampledDiffuseColor.b ) {
            don = uFace;
        }
        diffuseColor.rgb = don;
    }
}`;

const OUTLINE_VERTEX = /* glsl */ `
#include <common>
#include <skinning_pars_vertex>
uniform float uThickness;
void main() {
    #include <skinbase_vertex>
    #include <beginnormal_vertex>
    #include <skinnormal_vertex>
    #include <begin_vertex>
    #include <skinning_vertex>
    vec4 mvPosition = modelViewMatrix * vec4( transformed, 1.0 );
    vec3 viewNormal = normalize( ( modelViewMatrix * vec4( objectNormal, 0.0 ) ).xyz );
    mvPosition.xyz += viewNormal * uThickness;
    gl_Position = projectionMatrix * mvPosition;
}
`;

const OUTLINE_FRAGMENT = /* glsl */ `
uniform vec3 uColor;
void main() { gl_FragColor = vec4( uColor, 1.0 ); }
`;

export class DonchanRenderer {
    private readonly renderer: THREE.WebGLRenderer;
    private readonly scene = new THREE.Scene();
    private readonly camera: THREE.OrthographicCamera;
    private readonly loader = new GLTFLoader();

    private root: THREE.Group | null = null;
    private recolorTargets: RecolorEntry[] = [];
    private faceMaterial: THREE.MeshBasicMaterial | null = null;
    private faceImage: HTMLImageElement | null = null;
    private faceFrame = 0;

    // The animation rig (animations.glb) is a separate, flat skeleton storing global bone
    // transforms, whereas the cos models use a nested skeleton with the same bone names. We
    // play clips on the rig, then retarget by copying each rig bone's world matrix onto the
    // matching cos bone (as a local transform). Skinning stays correct because both share the
    // same global rest, so the cos inverse-bind matrices line up.
    private clips: THREE.AnimationClip[] = [];
    private clipsLoaded = false;
    private rig: THREE.Object3D | null = null;
    private rigBones = new Map<string, THREE.Object3D>();
    private targetBones: THREE.Bone[] = [];
    private mixer: THREE.AnimationMixer | null = null;
    private action: THREE.AnimationAction | null = null;
    private currentClip: string | null = null;
    private readonly clock = new THREE.Clock();
    private playing = false;
    private rafId = 0;
    private readonly tmpMatrix = new THREE.Matrix4();
    private readonly tmpParent = new THREE.Matrix4();

    private bodyBone: THREE.Object3D | null = null;
    private extent = 1;
    private readonly baseCenter = new THREE.Vector3();
    private readonly camTarget = new THREE.Vector3();
    private readonly baseYaw = THREE.MathUtils.degToRad(20);
    private userYaw = 0;
    private userPitch = 0;

    /** Called each animation frame with the current normalized time (0..1) while playing. */
    public onFrame: ((normalized: number) => void) | null = null;

    private colors: { body: RGB; face: RGB; rim: RGB } = {
        body: { r: 255, g: 255, b: 255 },
        face: { r: 255, g: 255, b: 255 },
        rim: { r: 255, g: 255, b: 255 },
    };

    public constructor(
        private readonly canvas: HTMLCanvasElement,
        private readonly size = 512,
    ) {
        this.renderer = new THREE.WebGLRenderer({
            canvas,
            alpha: true,
            antialias: true,
            preserveDrawingBuffer: true, // required for toDataURL screenshots
        });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.setSize(size, size, false);
        this.renderer.setClearColor(0x000000, 0);

        this.camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.01, 5000);
    }

    /** Load a kigurumi model and classify its recolor/face materials. */
    public async loadCostume(glbUrl: string, animationsUrl?: string): Promise<void> {
        if (animationsUrl && !this.clipsLoaded) {
            await this.loadAnimations(animationsUrl);
        }

        const gltf = await this.loader.loadAsync(glbUrl);

        this.disposeModel();

        const root = gltf.scene;
        this.root = root;
        this.recolorTargets = [];
        this.faceMaterial = null;

        const converted = new Map<THREE.Material, THREE.MeshBasicMaterial>();
        const outlineSources: THREE.SkinnedMesh[] = [];

        root.traverse((object) => {
            if (!(object as THREE.Mesh).isMesh) {
                return;
            }

            const mesh = object as THREE.Mesh;
            const original = mesh.material as THREE.MeshStandardMaterial;
            const shaderType = (original.userData?.shaderType as string | undefined) ?? '';

            let flat = converted.get(original);
            if (!flat) {
                flat = this.toFlatMaterial(original, shaderType);
                converted.set(original, flat);

                if (shaderType === SHADER_RECOLOR && flat.map?.image) {
                    const uniforms: RecolorUniforms = {
                        uBody: { value: new THREE.Color(1, 1, 1) },
                        uFace: { value: new THREE.Color(1, 1, 1) },
                        uRim: { value: new THREE.Color(1, 1, 1) },
                    };
                    this.attachRecolorShader(flat, uniforms);
                    this.recolorTargets.push({ material: flat, uniforms });
                } else if (shaderType === SHADER_FACE) {
                    this.faceMaterial = flat;
                }
            }

            mesh.material = flat;

            if ((mesh as THREE.SkinnedMesh).isSkinnedMesh) {
                outlineSources.push(mesh as THREE.SkinnedMesh);
            }
        });

        this.scene.add(root);
        this.applyColors();
        this.addOutline(outlineSources);
        this.setupAnimation();
        this.computeFrustum();
        this.applyRotation();
    }

    /** Spin/tilt the Don by the given deltas (radians). Pitch is clamped. */
    public rotateBy(deltaYaw: number, deltaPitch: number): void {
        this.userYaw += deltaYaw;
        this.userPitch = THREE.MathUtils.clamp(this.userPitch + deltaPitch, -Math.PI / 3, Math.PI / 3);
        this.applyRotation();
    }

    /** Return to the default 3/4 view. */
    public resetRotation(): void {
        this.userYaw = 0;
        this.userPitch = 0;
        this.applyRotation();
    }

    /** Clip names available for the animation chooser. */
    public get animationNames(): string[] {
        return this.clips.map((clip) => clip.name);
    }

    public get currentAnimation(): string | null {
        return this.currentClip;
    }

    /** Play a clip by name from the start, leaving the play/pause state unchanged. */
    public playClip(name: string): void {
        if (!this.mixer) {
            return;
        }

        const clip = THREE.AnimationClip.findByName(this.clips, name);
        if (!clip) {
            return;
        }

        this.currentClip = name;
        this.mixer.stopAllAction();
        this.action = this.mixer.clipAction(clip);
        this.action.reset();
        this.action.play();
        this.mixer.setTime(0);
        this.retarget();
        this.render();
        this.onFrame?.(0);
    }

    public get duration(): number {
        return this.action?.getClip().duration ?? 0;
    }

    /** Freeze the current clip at a normalized time (0..1) and render that frame. */
    public seek(normalized: number): void {
        if (!this.mixer || !this.action) {
            return;
        }

        this.setPlaying(false);
        this.mixer.setTime(THREE.MathUtils.clamp(normalized, 0, 1) * this.duration);
        this.retarget();
        this.render();
    }

    public setPlaying(playing: boolean): void {
        if (playing === this.playing) {
            return;
        }

        this.playing = playing;
        if (playing) {
            this.clock.getDelta(); // discard idle gap
            this.tick();
        } else if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = 0;
        }
    }

    private tick = (): void => {
        if (!this.playing || !this.mixer) {
            return;
        }

        this.mixer.update(this.clock.getDelta());
        this.retarget();
        this.render();

        if (this.action && this.duration > 0) {
            this.onFrame?.((this.action.time % this.duration) / this.duration);
        }

        this.rafId = requestAnimationFrame(this.tick);
    };

    /** Copy the posed rig's bone world transforms onto the cos skeleton (top-down). */
    private retarget(): void {
        if (!this.rig) {
            return;
        }

        this.rig.updateMatrixWorld(true);
        this.root?.updateMatrixWorld(true);
        for (const bone of this.targetBones) {
            const source = this.rigBones.get(bone.name);
            const parent = bone.parent;
            if (!source || !parent) {
                continue;
            }

            this.tmpParent.copy(parent.matrixWorld).invert();
            this.tmpMatrix.multiplyMatrices(this.tmpParent, source.matrixWorld);
            this.tmpMatrix.decompose(bone.position, bone.quaternion, bone.scale);
            bone.updateMatrixWorld(false);
        }

        this.updateCamera();
    }

    private setupAnimation(): void {
        if (!this.root) {
            return;
        }

        this.targetBones = [];
        this.bodyBone = null;
        this.root.traverse((object) => {
            if ((object as THREE.Bone).isBone) {
                this.targetBones.push(object as THREE.Bone);
                if (object.name === 'BODY') {
                    this.bodyBone = object;
                }
            }
        });

        if (this.rig && this.clips.length > 0) {
            this.mixer = new THREE.AnimationMixer(this.rig);
            this.action = null;
            const fallback = THREE.AnimationClip.findByName(this.clips, 'don_normal')
                ? 'don_normal'
                : this.clips[0].name;
            this.playClip(this.currentClip ?? fallback);
        }
    }

    /** Load the animation rig (flat skeleton) and its clips, used to drive the cos skeleton. */
    private async loadAnimations(url: string): Promise<void> {
        this.clipsLoaded = true;
        try {
            const gltf = await this.loader.loadAsync(url);
            this.rig = gltf.scene;
            this.clips = gltf.animations;
            this.rigBones.clear();
            this.rig.traverse((object) => {
                if (object.name) {
                    this.rigBones.set(object.name, object);
                }
            });
        } catch {
            this.rig = null;
            this.clips = [];
        }
    }

    /** Recolor body/face/rim. Indices map to the DonChan COLORS palette upstream. */
    public setColors(body: RGB, face: RGB, rim: RGB): void {
        this.colors = { body, face, rim };
        this.applyColors();
    }

    /** Load a face expression sheet and stamp the given frame (0..11) onto the face. */
    public async setFace(faceUrl: string, frame = 0): Promise<void> {
        this.faceImage = await this.loadImage(faceUrl);
        this.faceFrame = frame;
        this.stampFace();
    }

    /** Switch to a different expression frame from the already-loaded sheet. */
    public setFaceFrame(frame: number): void {
        this.faceFrame = ((frame % FACE_FRAME_COUNT) + FACE_FRAME_COUNT) % FACE_FRAME_COUNT;
        this.stampFace();
        this.render();
    }

    private stampFace(): void {
        if (!this.faceMaterial || !this.faceImage) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = FACE_FRAME;
        canvas.height = FACE_FRAME;
        const ctx = canvas.getContext('2d')!;
        ctx.drawImage(
            this.faceImage,
            0, this.faceFrame * FACE_FRAME, FACE_FRAME, FACE_FRAME,
            0, 0, FACE_FRAME, FACE_FRAME,
        );

        const texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.flipY = this.faceMaterial.map?.flipY ?? false;

        this.faceMaterial.map?.dispose();
        this.faceMaterial.map = texture;
        this.faceMaterial.needsUpdate = true;
    }

    public render(): void {
        this.renderer.render(this.scene, this.camera);
    }

    public screenshot(): string {
        this.render();

        return this.renderer.domElement.toDataURL('image/png');
    }

    public dispose(): void {
        this.setPlaying(false);
        this.mixer?.stopAllAction();
        this.disposeModel();
        this.renderer.dispose();
    }

    /**
     * Convert a glTF PBR material to an unlit textured one so the result matches the
     * game's flat custom shaders (which are not lit). userData is preserved so the
     * shaderType classification survives.
     */
    private toFlatMaterial(original: THREE.MeshStandardMaterial, shaderType: string): THREE.MeshBasicMaterial {
        const name = original.name.toLowerCase();
        const isFace = shaderType === SHADER_FACE;
        // Port of chara_3d.cpp material classification: "_aa_add" blends additively,
        // "_color_s_cus_" (without "_a_ab") is forced fully opaque so the white face/body
        // base renders solid instead of being punched through by its texture alpha.
        const isAdditive = name.includes('_aa_add') && !isFace;
        const isForceOpaque = name.includes('_color_s_cus_') && !name.includes('_a_ab');

        const flat = new THREE.MeshBasicMaterial({
            map: original.map ?? null,
            side: original.side,
            name: original.name,
        });
        flat.userData = original.userData;

        if (isFace) {
            flat.transparent = true;
            flat.depthWrite = false;
        } else if (isAdditive) {
            flat.transparent = true;
            flat.blending = THREE.AdditiveBlending;
            flat.depthWrite = false;
        } else if (isForceOpaque) {
            flat.transparent = false;
        } else {
            flat.transparent = true;
            flat.alphaTest = 0.5;
        }

        return flat;
    }

    /** Inject the channel-dominance remap into a recolor material's compiled shader. */
    private attachRecolorShader(material: THREE.MeshBasicMaterial, uniforms: RecolorUniforms): void {
        material.onBeforeCompile = (shader) => {
            shader.uniforms.uBody = uniforms.uBody;
            shader.uniforms.uFace = uniforms.uFace;
            shader.uniforms.uRim = uniforms.uRim;
            shader.fragmentShader =
                'uniform vec3 uBody;\nuniform vec3 uFace;\nuniform vec3 uRim;\n' +
                shader.fragmentShader.replace('#include <map_fragment>', RECOLOR_INJECT);
        };
        material.customProgramCacheKey = () => 'don-recolor';
    }

    private applyColors(): void {
        const { body, face, rim } = this.colors;
        for (const target of this.recolorTargets) {
            target.uniforms.uBody.value.setRGB(body.r / 255, body.g / 255, body.b / 255, THREE.SRGBColorSpace);
            target.uniforms.uFace.value.setRGB(face.r / 255, face.g / 255, face.b / 255, THREE.SRGBColorSpace);
            target.uniforms.uRim.value.setRGB(rim.r / 255, rim.g / 255, rim.b / 255, THREE.SRGBColorSpace);
        }
    }

    private addOutline(meshes: THREE.SkinnedMesh[]): void {
        if (meshes.length === 0 || !this.root) {
            return;
        }

        const box = new THREE.Box3().setFromObject(this.root);
        const thickness = box.getSize(new THREE.Vector3()).length() * 0.004;

        for (const mesh of meshes) {
            const material = new THREE.ShaderMaterial({
                vertexShader: OUTLINE_VERTEX,
                fragmentShader: OUTLINE_FRAGMENT,
                uniforms: {
                    uThickness: { value: thickness },
                    uColor: { value: new THREE.Color(0x000000) },
                },
                side: THREE.BackSide,
                defines: { USE_SKINNING: '' },
            });

            const outline = new THREE.SkinnedMesh(mesh.geometry, material);
            outline.bind(mesh.skeleton, mesh.bindMatrix);
            // Sibling, not child: a child would inherit the mesh transform AND re-apply
            // the skeleton, doubling the deformation and exploding the geometry.
            outline.position.copy(mesh.position);
            outline.quaternion.copy(mesh.quaternion);
            outline.scale.copy(mesh.scale);
            outline.renderOrder = -1;
            (mesh.parent ?? this.root).add(outline);
        }
    }

    /** Fix the orthographic frustum size from the bind-pose bounds (done once per costume). */
    private computeFrustum(): void {
        if (!this.root) {
            return;
        }

        const box = new THREE.Box3().setFromObject(this.root);
        box.getCenter(this.baseCenter);
        const size = box.getSize(new THREE.Vector3());
        this.extent = Math.max(size.x, size.y) * 0.62;

        this.camera.left = -this.extent;
        this.camera.right = this.extent;
        this.camera.top = this.extent;
        this.camera.bottom = -this.extent;
        this.camera.updateProjectionMatrix();
    }

    /** Keep the camera centred on the BODY bone so jumps/squashes stay in frame. */
    private updateCamera(): void {
        if (this.bodyBone) {
            this.bodyBone.getWorldPosition(this.camTarget);
        } else {
            this.camTarget.copy(this.baseCenter);
        }

        this.camera.position.set(this.camTarget.x, this.camTarget.y, this.camTarget.z + 1000);
        this.camera.lookAt(this.camTarget);
    }

    /** Apply the base 3/4 yaw plus any user spin/tilt. */
    private applyRotation(): void {
        const yaw = this.baseYaw + this.userYaw;

        if (this.rig) {
            // The rig carries the rotation; the cos root stays identity so the bone world
            // transforms retarget writes match what render's updateMatrixWorld recomputes
            // (rotating the root too would leave a stale-vs-fresh delta on the first apply).
            this.rig.rotation.set(this.userPitch, yaw, 0);
            this.root?.rotation.set(0, 0, 0);
            this.retarget();
        } else {
            this.root?.rotation.set(this.userPitch, yaw, 0);
            this.root?.updateMatrixWorld(true);
            this.updateCamera();
        }
        this.render();
    }

    private loadImage(url: string): Promise<HTMLImageElement> {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = () => resolve(image);
            image.onerror = reject;
            image.src = url;
        });
    }

    private disposeModel(): void {
        if (!this.root) {
            return;
        }

        this.scene.remove(this.root);
        this.root.traverse((object) => {
            const mesh = object as THREE.Mesh;
            if (mesh.isMesh) {
                mesh.geometry.dispose();
                const material = mesh.material as THREE.Material | THREE.Material[];
                if (Array.isArray(material)) {
                    material.forEach((m) => m.dispose());
                } else {
                    material.dispose();
                }
            }
        });
        this.root = null;
    }
}
