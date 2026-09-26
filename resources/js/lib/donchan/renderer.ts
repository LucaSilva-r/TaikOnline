/**
 * Client-side port of YataiDON's in-game Don-chan renderer
 * (YataiDON/src/objects/global/chara_3d.cpp). Loads a kigurumi cos/{id}.glb, recolors
 * materials by RGB channel dominance, stamps a face frame, draws an inverted-hull
 * outline, and can screenshot the canvas to a PNG data URL for use as a profile picture.
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

export type RGB = { r: number; g: number; b: number };
export type CameraRotation = { yaw: number; pitch: number };

/** glTF material.extras.shaderType tags (mirror of parse_glb_material_indices). */
const SHADER_RECOLOR = 'taikoEffectChangeColors';
const SHADER_FACE = 'taikoEffectFace';

const FACE_FRAME = 128; // each face sheet stacks 12 expression frames of 128x128.
export const FACE_FRAME_COUNT = 12;
// Matches the stock kigurumi face overlay; head/0's standalone overlay is larger.
const DEFAULT_FACE_OVERLAY_SIZE = 0.1369;

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

// Per-mesh black-line pass (port of YataiDON's outline shader). Back faces are
// pushed outward in screen space along the projected normal, scaled by the authored
// vertex-colour green weight, so seams/borders get a thin constant-width black line.
const BLACKLINE_VERTEX = /* glsl */ `
#include <common>
#include <skinning_pars_vertex>
uniform vec2 uOutlinePixel;
uniform float uNormalOffset;
uniform float uDepthBiasClip;
attribute vec4 color;
varying float vNormalZ;
varying float vBlackWeight;
void main() {
    #include <skinbase_vertex>
    #include <beginnormal_vertex>
    #include <skinnormal_vertex>
    #include <begin_vertex>
    #include <skinning_vertex>
    mat4 mvp = projectionMatrix * modelViewMatrix;
    vec4 clip = mvp * vec4( transformed, 1.0 );
    vec3 clipNormal = ( mvp * vec4( objectNormal, 0.0 ) ).xyz;
    vNormalZ = length( clipNormal ) > 1e-5 ? normalize( clipNormal ).z : 0.0;
    vBlackWeight = max( color.g, 0.0 );
    vec2 dir = clipNormal.xy;
    float l = length( dir );
    dir = l > 1e-5 ? dir / l : vec2( 0.0 );
    clip.xy += dir * uOutlinePixel * uNormalOffset * vBlackWeight;
    clip.z += uDepthBiasClip;
    gl_Position = clip;
}
`;

const BLACKLINE_FRAGMENT = /* glsl */ `
uniform float uNormalGate;
varying float vNormalZ;
varying float vBlackWeight;
void main() {
    if ( ( -vNormalZ + uNormalGate ) < 0.0 ) discard;
    if ( vBlackWeight <= 0.001 ) discard;
    gl_FragColor = vec4( 0.0, 0.0, 0.0, 1.0 );
}
`;

// Screen-space outer silhouette pass: passes covered pixels through and paints a
// black ring (radius uRadiusPx) around the model's alpha coverage.
const SCREEN_OUTLINE_VERTEX = /* glsl */ `
varying vec2 vUv;
void main() {
    vUv = uv;
    gl_Position = vec4( position.xy, 0.0, 1.0 );
}
`;

const SCREEN_OUTLINE_FRAGMENT = /* glsl */ `
precision highp float;
uniform sampler2D uScene;
uniform sampler2D uMask;
uniform vec2 uTexelSize;
uniform float uRadiusPx;
varying vec2 vUv;
// The ring is detected from the opaque-only mask so transparent parts (glass, netting,
// shuriken) never grow a silhouette; colour still comes from the full scene.
float maskAlpha( vec2 uv ) {
    if ( uv.x < 0.0 || uv.x > 1.0 || uv.y < 0.0 || uv.y > 1.0 ) return 0.0;
    return texture2D( uMask, uv ).a;
}
void main() {
    vec4 c = texture2D( uScene, vUv );
    if ( c.a > 0.001 ) {
        gl_FragColor = vec4( c.rgb, 1.0 );
    } else {
        vec2 r = uTexelSize * uRadiusPx;
        // Sample a full ring: more taps = smoother circular dilation, so edge-on flat
        // decals read as a rounded mass instead of a coarse 12-gon comb of spikes.
        const int TAPS = 32;
        float e = 0.0;
        for ( int i = 0; i < TAPS; i++ ) {
            float a = 6.2831853 * float( i ) / float( TAPS );
            e += maskAlpha( vUv + vec2( cos( a ), sin( a ) ) * r );
        }
        if ( e <= 0.001 ) discard;
        // Saturate coverage so thin spikes (hair tip, shuriken points), where only a
        // couple of the 12 taps land on the silhouette, stay solid black instead of faint.
        gl_FragColor = vec4( 0.0, 0.0, 0.0, clamp( e, 0.0, 1.0 ) );
    }
    // The scene target holds linear colour; let three encode it to the canvas colour space.
    #include <colorspace_fragment>
}
`;

// World-space inverted hull for every solid mesh: sharp geometry outlines the screen ring
// can't do (pointed tips, self-occlusion). Alpha discard keeps it to the textured shape so
// hard-alpha cutout decorations (kunai, etc.) outline their silhouette, not the card.
const HULL_VERTEX = /* glsl */ `
#include <common>
#include <skinning_pars_vertex>
uniform float uThickness;
varying vec2 vUv;
void main() {
    #include <skinbase_vertex>
    #include <beginnormal_vertex>
    #include <skinnormal_vertex>
    #include <begin_vertex>
    #include <skinning_vertex>
    vUv = uv;
    vec4 mvPosition = modelViewMatrix * vec4( transformed, 1.0 );
    vec3 viewNormal = normalize( ( modelViewMatrix * vec4( objectNormal, 0.0 ) ).xyz );
    mvPosition.xyz += viewNormal * uThickness;
    gl_Position = projectionMatrix * mvPosition;
}
`;

const HULL_FRAGMENT = /* glsl */ `
uniform sampler2D uMap;
uniform float uHasMap;
uniform float uAlphaTest;
varying vec2 vUv;
void main() {
    if ( uHasMap > 0.5 && texture2D( uMap, vUv ).a < uAlphaTest ) discard;
    gl_FragColor = vec4( 0.0, 0.0, 0.0, 1.0 );
}
`;

export class DonchanRenderer {
    private readonly renderer: THREE.WebGLRenderer;
    private readonly scene = new THREE.Scene();
    private readonly camera: THREE.OrthographicCamera;
    private readonly loader = new GLTFLoader();

    // Persistent container for the loaded model(s): a single cos kigurumi, or a head + body
    // pair composited together (both share the cos skeleton, so the rig drives them alike).
    private readonly root = new THREE.Group();
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
    private currentFrame = 0;
    private readonly timer = new THREE.Timer();
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

    // Two-pass toon outline: per-mesh black seam lines (drawn into the scene target) plus a
    // screen-space silhouette ring composited on the way to the canvas.
    private blackLineMaterials: THREE.ShaderMaterial[] = [];
    private hullMaterials: THREE.ShaderMaterial[] = [];
    private readonly smoothAlphaCache = new Map<TexImageSource, boolean>();
    private blackLineWidthPx = 12;
    private outerOutlineWidthPx = 12;
    private sceneTarget: THREE.WebGLRenderTarget | null = null;
    private maskTarget: THREE.WebGLRenderTarget | null = null;
    private readonly outlineScene = new THREE.Scene();
    private readonly outlineCamera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
    private readonly screenOutlineMaterial: THREE.ShaderMaterial;
    private readonly drawBufferSize = new THREE.Vector2();

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
        this.scene.add(this.root);

        this.screenOutlineMaterial = new THREE.ShaderMaterial({
            vertexShader: SCREEN_OUTLINE_VERTEX,
            fragmentShader: SCREEN_OUTLINE_FRAGMENT,
            uniforms: {
                uScene: { value: null },
                uMask: { value: null },
                uTexelSize: { value: new THREE.Vector2() },
                uRadiusPx: { value: this.outerOutlineWidthPx },
            },
            transparent: true,
            depthTest: false,
            depthWrite: false,
        });
        this.outlineScene.add(
            new THREE.Mesh(
                new THREE.PlaneGeometry(2, 2),
                this.screenOutlineMaterial,
            ),
        );
    }

    /** Tune outline thickness (drawing-buffer pixels): inner seam lines and outer ring. */
    public setOutlineWidths(blackLinePx: number, outerPx: number): void {
        this.blackLineWidthPx = Math.max(0, blackLinePx);
        this.outerOutlineWidthPx = Math.max(0, outerPx);
        this.render();
    }

    /**
     * Load the model set: one or more GLB URLs that share the cos skeleton. Pass a single
     * cos kigurumi, or a body + head pair to composite. Materials are classified for recolor
     * and face across every model.
     */
    public async loadModels(
        glbUrls: string[],
        animationsUrl?: string,
    ): Promise<void> {
        if (animationsUrl && !this.clipsLoaded) {
            await this.loadAnimations(animationsUrl);
        }

        const isCompositeModel = glbUrls.length > 1;
        const loaded = await Promise.all(
            glbUrls.map((url) => this.loader.loadAsync(url)),
        );

        this.disposeModels();
        this.recolorTargets = [];
        this.faceMaterial = null;

        const converted = new Map<THREE.Material, THREE.MeshBasicMaterial>();
        const outlineSources: THREE.SkinnedMesh[] = [];
        const hullSources: THREE.SkinnedMesh[] = [];

        for (const gltf of loaded) {
            gltf.scene.traverse((object) => {
                if (!(object as THREE.Mesh).isMesh) {
                    return;
                }

                const mesh = object as THREE.Mesh;
                const original = mesh.material as THREE.MeshStandardMaterial;
                const shaderType =
                    (original.userData?.shaderType as string | undefined) ?? '';

                if (isCompositeModel && shaderType === SHADER_FACE) {
                    this.normalizeCompositeFaceOverlay(mesh);
                }

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
                    } else if (
                        shaderType === SHADER_FACE &&
                        !this.faceMaterial
                    ) {
                        this.faceMaterial = flat;
                    }
                }

                mesh.material = flat;

                if ((mesh as THREE.SkinnedMesh).isSkinnedMesh) {
                    if (this.shouldOutline(flat, shaderType)) {
                        outlineSources.push(mesh as THREE.SkinnedMesh);
                    }

                    // Solid = anything that isn't see-through (opaque + hard-alpha cutouts
                    // like the kunai). These get the sharp hull outline.
                    if (!flat.userData.seeThrough) {
                        hullSources.push(mesh as THREE.SkinnedMesh);
                    }
                }
            });

            this.root.add(gltf.scene);
        }

        this.applyColors();
        this.addOutline(outlineSources);
        this.addHull(hullSources);
        this.setupAnimation();
        this.computeFrustum();
        this.applyRotation();
    }

    /** Spin/tilt the Don by the given deltas (radians). Pitch is clamped. */
    public rotateBy(deltaYaw: number, deltaPitch: number): void {
        this.userYaw = this.normalizeYaw(this.userYaw + deltaYaw);
        this.userPitch = THREE.MathUtils.clamp(
            this.userPitch + deltaPitch,
            -Math.PI / 3,
            Math.PI / 3,
        );
        this.applyRotation();
    }

    public setRotation(yaw: number, pitch: number): void {
        this.userYaw = this.normalizeYaw(yaw);
        this.userPitch = THREE.MathUtils.clamp(
            pitch,
            -Math.PI / 3,
            Math.PI / 3,
        );
        this.applyRotation();
    }

    public get cameraRotation(): CameraRotation {
        return {
            yaw: this.userYaw,
            pitch: this.userPitch,
        };
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

    public get animationFrame(): number {
        return this.currentFrame;
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
        this.currentFrame = 0;
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

        const clamped = THREE.MathUtils.clamp(normalized, 0, 1);
        this.setPlaying(false);
        this.mixer.setTime(clamped * this.duration);
        this.currentFrame = clamped;
        this.retarget();
        this.render();
        this.onFrame?.(clamped);
    }

    public setPlaying(playing: boolean): void {
        if (playing === this.playing) {
            return;
        }

        this.playing = playing;

        if (playing) {
            this.timer.update(performance.now()); // initialize/reset the timer so the next frame is calculated relative to now
            this.tick();
        } else if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = 0;
        }
    }

    private tick = (timestamp?: number): void => {
        if (!this.playing || !this.mixer) {
            return;
        }

        this.timer.update(timestamp ?? performance.now());
        this.mixer.update(this.timer.getDelta());
        this.retarget();
        this.render();

        if (this.action && this.duration > 0) {
            this.currentFrame =
                (this.action.time % this.duration) / this.duration;
            this.onFrame?.(this.currentFrame);
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
            this.tmpMatrix.decompose(
                bone.position,
                bone.quaternion,
                bone.scale,
            );
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
            const fallback = THREE.AnimationClip.findByName(
                this.clips,
                'don_normal',
            )
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
        this.faceFrame =
            ((frame % FACE_FRAME_COUNT) + FACE_FRAME_COUNT) % FACE_FRAME_COUNT;
        this.stampFace();
        this.render();
    }

    private normalizeCompositeFaceOverlay(mesh: THREE.Mesh): void {
        mesh.geometry.computeBoundingBox();
        const box = mesh.geometry.boundingBox;

        if (!box) {
            return;
        }

        const size = box.getSize(new THREE.Vector3());
        const largest = Math.max(size.x, size.y);

        if (largest <= DEFAULT_FACE_OVERLAY_SIZE * 1.05) {
            return;
        }

        const center = box.getCenter(new THREE.Vector3());
        const scale = DEFAULT_FACE_OVERLAY_SIZE / largest;
        const geometry = mesh.geometry.clone();
        const clonedPosition = geometry.getAttribute('position');

        for (let index = 0; index < clonedPosition.count; index++) {
            const x = clonedPosition.getX(index);
            const y = clonedPosition.getY(index);

            clonedPosition.setXY(
                index,
                center.x + (x - center.x) * scale,
                center.y + (y - center.y) * scale,
            );
        }

        clonedPosition.needsUpdate = true;
        geometry.computeBoundingBox();
        geometry.computeBoundingSphere();
        mesh.geometry = geometry;
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
            0,
            this.faceFrame * FACE_FRAME,
            FACE_FRAME,
            FACE_FRAME,
            0,
            0,
            FACE_FRAME,
            FACE_FRAME,
        );

        const texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.flipY = this.faceMaterial.map?.flipY ?? false;

        this.faceMaterial.map?.dispose();
        this.faceMaterial.map = texture;
        this.faceMaterial.needsUpdate = true;
    }

    public render(): void {
        const size = this.renderer.getDrawingBufferSize(this.drawBufferSize);
        this.ensureTargets(size.x, size.y);
        const scene = this.sceneTarget!;
        const mask = this.maskTarget!;

        for (const material of this.blackLineMaterials) {
            material.uniforms.uOutlinePixel.value.set(
                this.blackLineWidthPx / Math.max(1, size.x),
                this.blackLineWidthPx / Math.max(1, size.y),
            );
        }

        // Pass 1: opaque-only mask so the outer ring ignores transparent parts.
        const hidden = this.hideForMask();
        this.renderer.setRenderTarget(mask);
        this.renderer.clear();
        this.renderer.render(this.scene, this.camera);
        for (const object of hidden) {
            object.visible = true;
        }

        // Pass 2: full model (plus its black seam lines) into the scene target.
        this.renderer.setRenderTarget(scene);
        this.renderer.clear();
        this.renderer.render(this.scene, this.camera);

        // Pass 3: composite the scene colour and the silhouette ring onto the canvas.
        this.renderer.setRenderTarget(null);
        this.renderer.clear();
        const uniforms = this.screenOutlineMaterial.uniforms;
        uniforms.uScene.value = scene.texture;
        uniforms.uMask.value = mask.texture;
        uniforms.uTexelSize.value.set(
            1 / Math.max(1, size.x),
            1 / Math.max(1, size.y),
        );
        uniforms.uRadiusPx.value = this.outerOutlineWidthPx;
        this.renderer.render(this.outlineScene, this.outlineCamera);
    }

    /** Hide transparent meshes and outline helpers so the mask holds opaque coverage only. */
    private hideForMask(): THREE.Object3D[] {
        const hidden: THREE.Object3D[] = [];

        this.root.traverse((object) => {
            const mesh = object as THREE.Mesh;

            if (!mesh.isMesh || !object.visible) {
                return;
            }

            const material = mesh.material as THREE.Material;
            const seeThrough =
                !Array.isArray(material) && material.userData.seeThrough;

            if (seeThrough || object.userData.isOutlineHelper) {
                object.visible = false;
                hidden.push(object);
            }
        });

        return hidden;
    }

    private ensureTargets(width: number, height: number): void {
        if (
            this.sceneTarget &&
            this.sceneTarget.width === width &&
            this.sceneTarget.height === height
        ) {
            return;
        }

        this.sceneTarget?.dispose();
        this.maskTarget?.dispose();
        this.sceneTarget = new THREE.WebGLRenderTarget(width, height, {
            samples: 4,
        });
        this.maskTarget = new THREE.WebGLRenderTarget(width, height, {
            samples: 4,
        });
    }

    public screenshot(): string {
        this.render();

        return this.renderer.domElement.toDataURL('image/png');
    }

    public dispose(): void {
        this.setPlaying(false);
        this.mixer?.stopAllAction();
        this.disposeModels();
        this.sceneTarget?.dispose();
        this.maskTarget?.dispose();
        this.screenOutlineMaterial.dispose();
        this.renderer.dispose();
    }

    /**
     * Convert a glTF PBR material to an unlit textured one so the result matches the
     * game's flat custom shaders (which are not lit). userData is preserved so the
     * shaderType classification survives.
     */
    private toFlatMaterial(
        original: THREE.MeshStandardMaterial,
        shaderType: string,
    ): THREE.MeshBasicMaterial {
        const name = original.name.toLowerCase();
        const isFace = shaderType === SHADER_FACE;
        const isGlass = shaderType === 'taikoEffectGlass';
        const isDonBase =
            shaderType === SHADER_RECOLOR &&
            (name.startsWith('rgb_don_color') ||
                name.includes('don_facehip_color'));
        // Trust the glTF alphaMode, not the material-name tokens: BLEND surfaces as
        // original.transparent (alpha-blended overlays like visors/netting), MASK as
        // original.alphaTest (hard cutout). The name flags (A_AB/AA_ADD) lie about this.
        const isBlend = original.transparent === true && !isDonBase;
        const isMask = (original.alphaTest ?? 0) > 0;

        const flat = new THREE.MeshBasicMaterial({
            map: original.map ?? null,
            side:
                !isFace &&
                (isBlend || isMask || isGlass || name.includes('cullnone'))
                    ? THREE.DoubleSide
                    : original.side,
            name: original.name,
        });
        // See-through = glass, the face decal, or a blend surface whose texture is a smooth
        // alpha gradient (an actual lens). Hard-alpha blends (hair, shuriken, netting) are
        // solid and stay in the outline mask.
        const seeThrough =
            isFace ||
            isGlass ||
            (isBlend && this.hasSmoothAlpha(original.map));
        flat.userData = { ...original.userData, seeThrough };

        if (isFace) {
            flat.transparent = true;
            flat.depthWrite = false;
        } else if (isGlass) {
            flat.transparent = true;
            // Keep depth writes so the lens occludes/sorts against its border and the
            // rest of the model instead of painting over whatever drew first.
            flat.depthWrite = true;
            // Glass textures with no alpha (opaque reflections) need a material opacity
            // to read as see-through; textures carrying their own alpha define it.
            if (!original.transparent) {
                flat.opacity = 0.5;
            }
        } else if (isDonBase) {
            flat.transparent = false;
        } else if (isBlend) {
            // Alpha-blended overlay (visor/netting/shuriken) composited over the body.
            // Depth writes on so it sorts against other transparent parts rather than
            // painting by draw order; a low alphaTest discards fully-transparent texels
            // so they don't write depth and cull whatever is behind them.
            flat.transparent = true;
            flat.depthWrite = true;
            flat.alphaTest = 0.1;
        } else if (isMask) {
            flat.transparent = false;
            flat.alphaTest = original.alphaTest || 0.5;
            flat.depthWrite = true;
        } else {
            flat.transparent = false;
        }

        return flat;
    }

    /**
     * True when a texture's alpha is a smooth gradient (a real see-through lens) rather than
     * a hard 0/1 cutout (hair, netting, decals). Read back once per image and cached.
     */
    private hasSmoothAlpha(map: THREE.Texture | null): boolean {
        const image = map?.image as TexImageSource | undefined;

        if (!image) {
            return false;
        }

        const cached = this.smoothAlphaCache.get(image);
        if (cached !== undefined) {
            return cached;
        }

        let smooth = false;

        try {
            const source = image as CanvasImageSource & {
                width: number;
                height: number;
            };
            const width = Math.min(source.width || 64, 64);
            const height = Math.min(source.height || 64, 64);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d', {
                willReadFrequently: true,
            })!;
            ctx.drawImage(source, 0, 0, width, height);
            const data = ctx.getImageData(0, 0, width, height).data;

            let partial = 0;
            let total = 0;
            for (let i = 3; i < data.length; i += 4) {
                total++;
                if (data[i] > 20 && data[i] < 235) {
                    partial++;
                }
            }
            smooth = total > 0 && partial / total > 0.12;
        } catch {
            smooth = false;
        }

        this.smoothAlphaCache.set(image, smooth);

        return smooth;
    }

    private shouldOutline(
        material: THREE.Material,
        shaderType: string,
    ): boolean {
        if (shaderType === SHADER_FACE || shaderType === 'taikoEffectGlass') {
            return false;
        }

        const flat = material as THREE.MeshBasicMaterial;

        // Transparent/cutout surfaces skip the inverted-hull outline: a solid black
        // hull behind a see-through mesh would show through it.
        return !flat.transparent && !flat.alphaTest;
    }

    /** Inject the channel-dominance remap into a recolor material's compiled shader. */
    private attachRecolorShader(
        material: THREE.MeshBasicMaterial,
        uniforms: RecolorUniforms,
    ): void {
        material.onBeforeCompile = (shader) => {
            shader.uniforms.uBody = uniforms.uBody;
            shader.uniforms.uFace = uniforms.uFace;
            shader.uniforms.uRim = uniforms.uRim;
            shader.fragmentShader =
                'uniform vec3 uBody;\nuniform vec3 uFace;\nuniform vec3 uRim;\n' +
                shader.fragmentShader.replace(
                    '#include <map_fragment>',
                    RECOLOR_INJECT,
                );
        };
        material.customProgramCacheKey = () => 'don-recolor';
    }

    private applyColors(): void {
        const { body, face, rim } = this.colors;

        for (const target of this.recolorTargets) {
            target.uniforms.uBody.value.setRGB(
                body.r / 255,
                body.g / 255,
                body.b / 255,
                THREE.SRGBColorSpace,
            );
            target.uniforms.uFace.value.setRGB(
                face.r / 255,
                face.g / 255,
                face.b / 255,
                THREE.SRGBColorSpace,
            );
            target.uniforms.uRim.value.setRGB(
                rim.r / 255,
                rim.g / 255,
                rim.b / 255,
                THREE.SRGBColorSpace,
            );
        }
    }

    private addOutline(meshes: THREE.SkinnedMesh[]): void {
        for (const material of this.blackLineMaterials) {
            material.dispose();
        }
        this.blackLineMaterials = [];

        if (meshes.length === 0 || !this.root) {
            return;
        }

        for (const mesh of meshes) {
            const material = new THREE.ShaderMaterial({
                vertexShader: BLACKLINE_VERTEX,
                fragmentShader: BLACKLINE_FRAGMENT,
                uniforms: {
                    uOutlinePixel: { value: new THREE.Vector2() },
                    uNormalOffset: { value: 1.0 },
                    uNormalGate: { value: 0.8 },
                    uDepthBiasClip: { value: 0.0001 },
                },
                side: THREE.BackSide,
                transparent: true,
                depthWrite: false,
                defines: { USE_SKINNING: '' },
            });

            const outline = new THREE.SkinnedMesh(mesh.geometry, material);
            outline.bind(mesh.skeleton, mesh.bindMatrix);
            // Sibling, not child: a child would inherit the mesh transform AND re-apply
            // the skeleton, doubling the deformation and exploding the geometry.
            outline.position.copy(mesh.position);
            outline.quaternion.copy(mesh.quaternion);
            outline.scale.copy(mesh.scale);
            // Draw after the model so the depth test hides interior/occluded seam lines.
            outline.renderOrder = 20;
            outline.userData.isOutlineHelper = true;
            (mesh.parent ?? this.root).add(outline);
            this.blackLineMaterials.push(material);
        }
    }

    /** Inverted-hull outline for every solid mesh: sharp geometry outlines + self-occlusion. */
    private addHull(meshes: THREE.SkinnedMesh[]): void {
        for (const material of this.hullMaterials) {
            material.dispose();
        }
        this.hullMaterials = [];

        if (meshes.length === 0 || !this.root) {
            return;
        }

        const box = new THREE.Box3().setFromObject(this.root);
        const thickness = box.getSize(new THREE.Vector3()).length() * 0.004;

        for (const mesh of meshes) {
            const map = (mesh.material as THREE.MeshBasicMaterial).map ?? null;
            const material = new THREE.ShaderMaterial({
                vertexShader: HULL_VERTEX,
                fragmentShader: HULL_FRAGMENT,
                uniforms: {
                    uThickness: { value: thickness },
                    uMap: { value: map },
                    uHasMap: { value: map ? 1 : 0 },
                    uAlphaTest: { value: 0.5 },
                },
                side: THREE.BackSide,
                defines: { USE_SKINNING: '' },
            });

            const hull = new THREE.SkinnedMesh(mesh.geometry, material);
            hull.bind(mesh.skeleton, mesh.bindMatrix);
            hull.position.copy(mesh.position);
            hull.quaternion.copy(mesh.quaternion);
            hull.scale.copy(mesh.scale);
            // Drawn before the body so it only shows at silhouettes/self-occlusion gaps.
            hull.renderOrder = -1;
            hull.userData.isOutlineHelper = true;
            (mesh.parent ?? this.root).add(hull);
            this.hullMaterials.push(material);
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

        this.camera.position.set(
            this.camTarget.x,
            this.camTarget.y,
            this.camTarget.z + 1000,
        );
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

    private normalizeYaw(yaw: number): number {
        return (
            THREE.MathUtils.euclideanModulo(yaw + Math.PI, Math.PI * 2) -
            Math.PI
        );
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

    private disposeModels(): void {
        this.root.traverse((object) => {
            const mesh = object as THREE.Mesh;

            if (mesh.isMesh) {
                mesh.geometry.dispose();
                const material = mesh.material as
                    | THREE.Material
                    | THREE.Material[];

                if (Array.isArray(material)) {
                    material.forEach((m) => m.dispose());
                } else {
                    material.dispose();
                }
            }
        });
        this.root.clear();
    }
}
